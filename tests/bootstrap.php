<?php

declare(strict_types=1);

// PHPUnit bootstrap.
//
// phpunit.xml has pointed at this file for a long time, but it did not exist
// and phpunit was not installed, so the suite could never run. That mattered:
// MemberImporterTest covers buildMember(), the call site that silently erased
// members' GDPR consent records on re-import.

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

// Unity's interfaces are loaded from the real plugin in the sibling directory,
// which is exactly what WordPress loads at runtime. Reading the real files
// rather than a hand-copy means a change to Unity's contract fails these tests
// immediately instead of going unnoticed until production.
//
// Deliberately not a Composer path repository: that would be a hard
// require-dev, and `composer install` — a CI gate — fails outright when
// ../unity is absent. CI checks Unity out as a sibling before installing.
$unitySrc = dirname(__DIR__, 2) . '/unity/src';

if (!is_dir($unitySrc)) {
    fwrite(STDERR, PHP_EOL . 'ERROR: Unity plugin source not found at ' . $unitySrc . PHP_EOL
        . 'Reconcile is built on Unity\'s interfaces, so the Unity plugin must be' . PHP_EOL
        . 'checked out as a sibling directory for this suite to run.' . PHP_EOL . PHP_EOL);
    exit(1);
}

spl_autoload_register(static function (string $class) use ($unitySrc): void {
    if (!str_starts_with($class, 'Unity\\')) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen('Unity\\')));
    $file     = $unitySrc . '/' . $relative . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

// Reconcile's importers reach for a handful of WordPress functions on their
// error paths. These are pure, so use the real semantics rather than making
// every test that touches a failure branch declare a mock.
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim(strip_tags($str));
    }
}

// GroupImporter (unlike MemberImporter, which persists through a repository)
// calls WordPress post functions directly. Stub the handful it uses so the
// persisting-path tests can run without a WordPress runtime.
if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        // WP_Error is not loaded in the unit suite; instanceof against an
        // undefined class is simply false, which is the behaviour we want.
        return $thing instanceof \WP_Error;
    }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post(array $postarr, bool $wp_error = false): int
    {
        // The create-path test seeds the returned post ID via this global.
        return (int) ($GLOBALS['__reconcile_test_wp_insert_post_returns'] ?? 1);
    }
}

if (!function_exists('wp_update_post')) {
    function wp_update_post(array $postarr, bool $wp_error = false): int
    {
        return (int) ($postarr['ID'] ?? 1);
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $postId, string $key, mixed $value): bool
    {
        return true;
    }
}

// do_action serves two suites: it is a no-op for unity/member_import (fired by
// MemberImporter, irrelevant to import logic) and captures unity/group_changing
// so GroupImporterChangingEventTest can assert the pre/post-write state. Kept
// here rather than in a test file so it is defined before any test runs,
// regardless of file load order.
if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        if (
            $hook === 'unity/group_changing'
            && count($args) === 2
            && class_exists(\Reconcile\Tests\Unit\Import\GroupImporterChangingEventTest::class)
        ) {
            \Reconcile\Tests\Unit\Import\GroupImporterChangingEventTest::$dispatchedGroupChangingEvents[] = [
                $args[0],
                $args[1],
            ];
        }
    }
}

// ── AJAX / admin-post handler stubs ─────────────────────────────────
//
// The import and export handlers are thin WordPress AJAX/admin-post
// endpoints. Their real terminal functions (wp_send_json_*, wp_die) end
// the request; here they throw a catchable exception carrying the payload
// so a test can assert on the response instead of the process exiting.
//
// current_user_can() and wp_verify_nonce() default to "allowed" so a happy
// path runs without ceremony; tests flip the globals to exercise the
// permission and nonce failure branches.

if (!class_exists('Reconcile\Tests\HandlerHalt')) {
    class ReconcileHandlerHalt extends \RuntimeException
    {
        /** @param array<string,mixed>|string $payload */
        public function __construct(
            public readonly string $kind,
            public readonly mixed $payload = null,
            public readonly int $statusCode = 0
        ) {
            parent::__construct($kind);
        }
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return (bool) ($GLOBALS['__reconcile_test_can'] ?? true);
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action = ''): bool
    {
        return (bool) ($GLOBALS['__reconcile_test_nonce_valid'] ?? true);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return $value;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success(mixed $data = null, int $status = 0): void
    {
        throw new \ReconcileHandlerHalt('json_success', $data, $status);
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error(mixed $data = null, int $status = 0): void
    {
        throw new \ReconcileHandlerHalt('json_error', $data, $status);
    }
}

if (!function_exists('wp_die')) {
    function wp_die(mixed $message = '', mixed $title = '', mixed $args = []): void
    {
        throw new \ReconcileHandlerHalt('wp_die', $message, is_int($title) ? $title : 0);
    }
}

// ── Filesystem stubs for ImportTempDir ──────────────────────────────

if (!function_exists('get_temp_dir')) {
    function get_temp_dir(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . '/';
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $dir): bool
    {
        return is_dir($dir) || mkdir($dir, 0777, true) || is_dir($dir);
    }
}

if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name(string $filename): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?? $filename;
    }
}

if (!function_exists('wp_unique_filename')) {
    function wp_unique_filename(string $dir, string $filename): string
    {
        $candidate = $filename;
        $i = 1;
        while (file_exists(rtrim($dir, '/\\') . '/' . $candidate)) {
            $candidate = pathinfo($filename, PATHINFO_FILENAME) . "-{$i}." . pathinfo($filename, PATHINFO_EXTENSION);
            $i++;
        }
        return $candidate;
    }
}

// Namespace-local overrides of the upload builtins ImportTempDir uses.
require_once __DIR__ . '/CoreFunctionOverrides.php';
