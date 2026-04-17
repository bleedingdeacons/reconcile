<?php

declare(strict_types=1);

namespace Reconcile\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * Import Temporary Directory Helper
 *
 * Provides a non-web-accessible location for import file uploads and
 * a shutdown-safe wrapper for moving and cleaning up uploaded files.
 *
 * Two strategies, tried in order:
 *   1. The system temp dir reported by get_temp_dir() (typically outside
 *      the web root, e.g. /tmp). Preferred.
 *   2. A hardened fallback under WP_CONTENT_DIR with .htaccess deny rules
 *      and an empty index.php, used only if the system temp dir is not
 *      writable.
 *
 * Files are registered for shutdown-time deletion so they do not linger
 * if PHP fatals, hits the time limit, or is killed mid-request.
 */
class ImportTempDir
{
    /**
     * Hard ceiling on accepted upload size (bytes). 50 MB.
     * Applied in addition to PHP's upload_max_filesize.
     */
    public const MAX_UPLOAD_BYTES = 50 * 1024 * 1024;

    /**
     * MIME types accepted for .csv uploads.
     * Browsers and OSes disagree widely here; this list is intentionally
     * permissive but rejects obvious mismatches like text/html.
     *
     * @var string[]
     */
    private const CSV_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'application/octet-stream',
    ];

    /**
     * MIME types accepted for .xlsx uploads.
     * XLSX is a ZIP archive, so application/zip is legitimate.
     *
     * @var string[]
     */
    private const XLSX_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/octet-stream',
    ];

    /**
     * Paths registered for shutdown cleanup.
     *
     * @var string[]
     */
    private static array $registeredPaths = [];

    /**
     * Whether the shutdown cleanup callback has been registered.
     */
    private static bool $shutdownRegistered = false;

    /**
     * Return the directory that should be used for import temp files.
     *
     * Creates and hardens the directory on first use. Throws if no
     * writable location can be established.
     *
     * @throws RuntimeException If no writable temp location is available.
     */
    public static function path(): string
    {
        // Preferred: system temp dir (outside web root on every sane host).
        $systemTmp = rtrim(\get_temp_dir(), '/\\');

        if ($systemTmp !== '' && is_dir($systemTmp) && is_writable($systemTmp)) {
            $dir = $systemTmp . '/reconcile-tmp/';

            if (!file_exists($dir)) {
                \wp_mkdir_p($dir);
            }

            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }
        }

        // Fallback: hardened directory under wp-content. Not ideal — the
        // web root usually contains wp-content — so harden with .htaccess
        // and an empty index.php before returning the path.
        $fallback = rtrim(WP_CONTENT_DIR, '/\\') . '/reconcile-tmp/';

        if (!file_exists($fallback)) {
            \wp_mkdir_p($fallback);
        }

        if (!is_dir($fallback) || !is_writable($fallback)) {
            throw new RuntimeException(
                'Could not establish a writable temp directory for import uploads.'
            );
        }

        self::harden($fallback);

        return $fallback;
    }

    /**
     * Move an uploaded file into the temp dir, register it for shutdown
     * cleanup, and return its absolute path.
     *
     * @param array{name: string, tmp_name: string, size?: int} $file
     *     Entry from $_FILES. tmp_name must be a valid uploaded file.
     *
     * @throws RuntimeException If the upload is rejected or cannot be moved.
     */
    public static function accept(array $file): string
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Invalid upload: tmp_name is not an uploaded file.');
        }

        $size = (int) ($file['size'] ?? filesize($file['tmp_name']) ?: 0);

        if ($size <= 0) {
            throw new RuntimeException('Invalid upload: file is empty.');
        }

        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException(sprintf(
                'File exceeds the %d MB upload limit.',
                (int) (self::MAX_UPLOAD_BYTES / 1024 / 1024)
            ));
        }

        $name = (string) ($file['name'] ?? 'upload');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        // Defence-in-depth: MIME sniff the actual bytes. Extension alone is
        // trivially spoofed (an .html renamed to .csv passes extension
        // checks). finfo reads the file header, which cannot be forged by
        // simply renaming.
        self::assertMimeMatchesExtension($file['tmp_name'], $extension);

        $dir = self::path();
        $target = $dir . \wp_unique_filename($dir, \sanitize_file_name($name));

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new RuntimeException('Could not move uploaded file into temp directory.');
        }

        // Belt-and-braces: POSIX perms on the file. wp_mkdir_p already
        // applies the configured dir perms.
        @chmod($target, 0600);

        self::registerForCleanup($target);

        return $target;
    }

    /**
     * Delete a file now and remove it from the shutdown cleanup list.
     *
     * Safe to call multiple times.
     */
    public static function cleanup(string $path): void
    {
        if ($path !== '' && file_exists($path)) {
            @unlink($path);
        }

        self::$registeredPaths = array_values(array_filter(
            self::$registeredPaths,
            static fn (string $p): bool => $p !== $path
        ));
    }

    /**
     * Register a path for deletion during PHP shutdown.
     *
     * Shutdown handlers run even when a fatal error or timeout aborts
     * normal execution, so this closes the window where a temp file
     * would otherwise linger on disk.
     */
    private static function registerForCleanup(string $path): void
    {
        self::$registeredPaths[] = $path;

        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;

        register_shutdown_function(static function (): void {
            foreach (self::$registeredPaths as $path) {
                if ($path !== '' && file_exists($path)) {
                    @unlink($path);
                }
            }
            self::$registeredPaths = [];
        });
    }

    /**
     * Write .htaccess and index.php into the directory to block direct
     * web access, in case the hosting setup leaves the directory inside
     * the document root.
     *
     * Idempotent: rewrites files only when missing.
     */
    private static function harden(string $dir): void
    {
        $dir = rtrim($dir, '/\\') . '/';

        $htaccess = $dir . '.htaccess';
        if (!file_exists($htaccess)) {
            // Apache 2.4 uses `Require all denied`; older 2.2 deployments
            // still understand `Deny from all`. Including both is the
            // conventional hardening idiom used by WordPress core.
            $rules = "# Reconcile plugin: deny direct access to temp uploads.\n"
                . "<IfModule mod_authz_core.c>\n"
                . "    Require all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "    Order allow,deny\n"
                . "    Deny from all\n"
                . "</IfModule>\n";
            @file_put_contents($htaccess, $rules);
        }

        $index = $dir . 'index.php';
        if (!file_exists($index)) {
            // Empty index.php masks the directory listing on servers that
            // ignore .htaccess (nginx, LiteSpeed without rewrite).
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        $indexHtml = $dir . 'index.html';
        if (!file_exists($indexHtml)) {
            @file_put_contents($indexHtml, '');
        }
    }

    /**
     * Throw if the file's sniffed MIME type is incompatible with its
     * claimed extension.
     *
     * @throws RuntimeException
     */
    private static function assertMimeMatchesExtension(string $path, string $extension): void
    {
        // finfo is part of a default PHP build but technically optional.
        // If it is missing, skip the check rather than block the upload —
        // the SpreadsheetReader's structural validation will catch an
        // HTML-as-CSV file when it fails to parse.
        if (!function_exists('finfo_open')) {
            return;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        if ($mime === false) {
            return;
        }

        $mime = strtolower($mime);

        $allowed = match ($extension) {
            'csv'  => self::CSV_MIME_TYPES,
            'xlsx' => self::XLSX_MIME_TYPES,
            default => [],
        };

        if ($allowed === [] || !in_array($mime, $allowed, true)) {
            throw new RuntimeException(sprintf(
                'File content (%s) does not match the .%s extension.',
                $mime,
                $extension
            ));
        }
    }
}