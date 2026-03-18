<?php

declare(strict_types=1);

namespace Reconcile\Member;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Member Export Handler
 *
 * Handles the admin-post endpoint for the member export.
 * Generates a CSV file and sends it as a download.
 */
class MemberExportHandler
{
    private MemberExporter $exporter;

    public function __construct(MemberExporter $exporter)
    {
        $this->exporter = $exporter;
    }

    /**
     * Register the admin-post action.
     */
    public function register(): void
    {
        add_action('admin_post_reconcile_member_export', [$this, 'handleExport']);
    }

    /**
     * Handle the export request.
     */
    public function handleExport(): void
    {
        error_log('Reconcile Member Export: Handler invoked.');

        // Security checks
        if (!current_user_can('manage_options')) {
            error_log('Reconcile Member Export: Permission denied.');
            wp_die(__('You do not have permission to perform this action.', 'reconcile'), 403);
        }

        if (
            !isset($_GET['_wpnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'reconcile_member_export')
        ) {
            error_log('Reconcile Member Export: Nonce verification failed.');
            wp_die(__('Security check failed. Please go back and try again.', 'reconcile'), 403);
        }

        try {
            $csv = $this->exporter->export();
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Reconcile Member Export: Error — ' . get_class($e) . ': ' . $e->getMessage());
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Reconcile Member Export: Stack trace — ' . $e->getTraceAsString());
            wp_die(
                __('Export failed: ', 'reconcile') . esc_html($e->getMessage()),
                __('Export Error', 'reconcile'),
                ['back_link' => true]
            );
        }

        $filename = 'members-export-' . gmdate('Y-m-d-His') . '.csv';

        error_log('Reconcile Member Export: Sending CSV download — ' . $filename . '.');

        // Send as file download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csv));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $csv;
        exit;
    }
}
