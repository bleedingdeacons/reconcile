<?php

declare(strict_types=1);

namespace Reconcile\Member;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import Handler
 *
 * Handles the AJAX endpoint for the member import form.
 * Validates the upload, hands the file to MemberImporter, and returns JSON.
 */
class MemberImportHandler
{
    private MemberImporter $importer;

    public function __construct(MemberImporter $importer)
    {
        $this->importer = $importer;
    }

    /**
     * Register the AJAX action.
     */
    public function register(): void
    {
        add_action('wp_ajax_reconcile_import', [$this, 'handleImport']);
    }

    /**
     * Handle the AJAX import request.
     */
    public function handleImport(): void
    {
        \Reconcile\Plugin::logDebug('Reconcile Member Import: AJAX handler invoked.');

        // Security checks
        if (!current_user_can('manage_options')) {
            \Reconcile\Plugin::logWarning('Reconcile Member Import: Permission denied — user lacks manage_options capability.');
            wp_send_json_error(['message' => 'You do not have permission to perform this action.'], 403);
        }

        if (
            !isset($_POST['reconcile_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['reconcile_nonce'])), 'reconcile_import')
        ) {
            \Reconcile\Plugin::logWarning('Reconcile Member Import: Nonce verification failed.');
            wp_send_json_error(['message' => 'Security check failed. Please refresh and try again.'], 403);
        }

        // Validate file upload
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            \Reconcile\Plugin::logError('Reconcile Member Import: File upload failed with code ' . $errorCode . '.');
            wp_send_json_error([
                'message' => 'File upload failed: ' . $this->uploadErrorMessage($errorCode),
            ], 400);
        }

        $file = $_FILES['import_file'];
        \Reconcile\Plugin::logDebug('Reconcile Member Import: Received file "' . $file['name'] . '" (' . $file['size'] . ' bytes).');

        // Validate MIME type / extension
        $allowedExtensions = ['csv', 'xlsx'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            \Reconcile\Plugin::logWarning('Reconcile Member Import: Rejected file type .' . $extension . '.');
            wp_send_json_error([
                'message' => "Invalid file type: .{$extension}. Please upload a .csv or .xlsx file.",
            ], 400);
        }

        // Move to a non-web-accessible temp location. ImportTempDir prefers
        // the system temp dir, falls back to a hardened dir under wp-content
        // with .htaccess + index.php, sniffs the MIME type to reject files
        // whose extension does not match their content, and registers the
        // file for shutdown-time cleanup so it does not linger if PHP fatals
        // or times out.
        try {
            $tempFile = \Reconcile\Core\ImportTempDir::accept($file);
        } catch (\Throwable $e) {
            \Reconcile\Plugin::logError('Reconcile Member Import: Rejected upload — ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()], 400);
            return;
        }

        \Reconcile\Plugin::logDebug('Reconcile Member Import: File moved to ' . $tempFile . '.');

        // Determine dry-run mode
        $dryRun = isset($_POST['dry_run']) && $_POST['dry_run'] === '1';
        \Reconcile\Plugin::logDebug('Reconcile Member Import: Dry run = ' . ($dryRun ? 'yes' : 'no') . '.');

        // Run the import
        try {
            $result = $this->importer->import($tempFile, $dryRun);
        } catch (\Throwable $e) {
            \Reconcile\Core\ImportTempDir::cleanup($tempFile);
            \Reconcile\Plugin::logError('Reconcile Member Import: Uncaught exception — ' . get_class($e) . ': ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            // Stack trace now captured in logger context
            wp_send_json_error(['message' => 'Import failed unexpectedly: ' . $e->getMessage()], 500);
            return; // unreachable but explicit
        }

        // Cleanup the temp file. ImportTempDir also has a shutdown handler
        // registered that will delete this file even if the request is
        // aborted before reaching this line.
        \Reconcile\Core\ImportTempDir::cleanup($tempFile);

        // Log result summary
        \Reconcile\Plugin::logInfo('Reconcile Member Import: ' . $result->getSummary());

        if ($result->hasWarnings()) {
            foreach ($result->getWarnings() as $warning) {
                \Reconcile\Plugin::logWarning('Reconcile Member Import Warning: ' . $warning);
            }
        }

        if ($result->hasErrors()) {
            foreach ($result->getErrors() as $error) {
                \Reconcile\Plugin::logError('Reconcile Member Import Error: ' . $error);
            }
        }

        // Return result
        if ($result->isSuccess()) {
            wp_send_json_success($result->toArray());
        } else {
            wp_send_json_error($result->toArray(), 422);
        }
    }

    /**
     * Human-readable upload error message.
     */
    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE   => 'The file exceeds the server upload size limit.',
            UPLOAD_ERR_FORM_SIZE  => 'The file exceeds the form upload size limit.',
            UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A server extension stopped the upload.',
            default               => "Unknown error (code {$code}).",
        };
    }
}