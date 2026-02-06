<?php

declare(strict_types=1);

namespace Reconcile\Admin;

use Reconcile\Import\MemberImporter;

/**
 * Import Handler
 *
 * Handles the AJAX endpoint for the member import form.
 * Validates the upload, hands the file to MemberImporter, and returns JSON.
 */
class ImportHandler
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
        // Security checks
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'You do not have permission to perform this action.'], 403);
        }

        if (
            !isset($_POST['reconcile_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['reconcile_nonce'])), 'reconcile_import')
        ) {
            wp_send_json_error(['message' => 'Security check failed. Please refresh and try again.'], 403);
        }

        // Validate file upload
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            wp_send_json_error([
                'message' => 'File upload failed: ' . $this->uploadErrorMessage($errorCode),
            ], 400);
        }

        $file = $_FILES['import_file'];

        // Validate MIME type / extension
        $allowedExtensions = ['csv', 'xlsx'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            wp_send_json_error([
                'message' => "Invalid file type: .{$extension}. Please upload a .csv or .xlsx file.",
            ], 400);
        }

        // Move to a temporary location WordPress manages
        $uploadDir = wp_upload_dir();
        $tempDir = trailingslashit($uploadDir['basedir']) . 'reconcile-tmp/';

        if (!file_exists($tempDir)) {
            wp_mkdir_p($tempDir);
        }

        $tempFile = $tempDir . wp_unique_filename($tempDir, sanitize_file_name($file['name']));

        if (!move_uploaded_file($file['tmp_name'], $tempFile)) {
            wp_send_json_error(['message' => 'Could not move uploaded file.'], 500);
        }

        // Determine dry-run mode
        $dryRun = isset($_POST['dry_run']) && $_POST['dry_run'] === '1';

        // Run the import
        try {
            $result = $this->importer->import($tempFile, $dryRun);
        } catch (\Throwable $e) {
            // Cleanup
            @unlink($tempFile);
            error_log('Reconcile import error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Import failed unexpectedly: ' . $e->getMessage()], 500);
            return; // unreachable but explicit
        }

        // Cleanup the temp file
        @unlink($tempFile);

        // Also try to remove the temp directory if empty
        @rmdir($tempDir);

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
