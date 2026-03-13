<?php

declare(strict_types=1);

namespace Reconcile\Admin;

use Reconcile\Position\PositionColumnMapper;

/**
 * Position Import Admin Page
 *
 * Renders the Position Import admin page and enqueues its assets.
 * Menu registration is handled by the Plugin class to ensure correct timing.
 */
class PositionsAdmin
{
    /**
     * Register asset enqueuing.
     */
    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * Enqueue admin CSS and JS only on our page.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'reconcile_page_reconcile-positions') {
            return;
        }

        wp_enqueue_style(
            'reconcile-admin',
            RECONCILE_URL . 'assets/css/admin.css',
            [],
            RECONCILE_VERSION
        );

        wp_enqueue_script(
            'reconcile-position-admin',
            RECONCILE_URL . 'assets/js/position-admin.js',
            ['jquery'],
            RECONCILE_VERSION,
            true
        );

        wp_localize_script('reconcile-position-admin', 'reconcilePositionAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('reconcile_position_import'),
        ]);
    }

    /**
     * Render the admin page.
     */
    public function renderPage(): void
    {
        $acceptedHeaders = PositionColumnMapper::getAcceptedHeaders();
        $labels = PositionColumnMapper::getPropertyLabels();
        $notes = self::getPropertyNotes();

        ?>
        <div class="wrap reconcile-wrap">
            <h1><?php esc_html_e('Reconcile — Position Import', 'reconcile'); ?></h1>

            <div class="reconcile-card">
                <h2><?php esc_html_e('Import Positions from Spreadsheet', 'reconcile'); ?></h2>
                <p class="description">
                    <?php esc_html_e(
                        'Upload a .csv or .xlsx file with position data. The first row must contain column headers '
                        . 'that match the expected property names. Each row must include either a Position ID or a '
                        . 'Position Name (or both) to identify the position to update. If both are provided, the position '
                        . 'is looked up by ID and its name is updated. If only a name is provided, it is used to '
                        . 'find the matching position. '
                        . 'Only non-empty fields in the spreadsheet will be updated; empty fields are left unchanged.',
                        'reconcile'
                    ); ?>
                </p>

                <table class="reconcile-column-table widefat striped">
                    <thead>
                    <tr>
                        <th><?php esc_html_e('Property', 'reconcile'); ?></th>
                        <th><?php esc_html_e('Accepted Column Headers', 'reconcile'); ?></th>
                        <th><?php esc_html_e('Notes', 'reconcile'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($acceptedHeaders as $property => $aliases): ?>
                        <tr>
                            <td><strong><?php echo esc_html($labels[$property] ?? $property); ?></strong></td>
                            <td>
                                <?php
                                $escapedAliases = array_map(
                                    fn($alias) => '<code>' . esc_html($alias) . '</code>',
                                    $aliases
                                );
                                echo implode(', ', $escapedAliases);
                                ?>
                            </td>
                            <td>
                                <?php
                                if (isset($notes[$property])) {
                                    echo wp_kses($notes[$property], [
                                        'code' => [],
                                        'strong' => [],
                                        'em' => [],
                                        'br' => [],
                                    ]);
                                } else {
                                    echo '<span class="reconcile-note-muted">—</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <form id="reconcile-position-import-form" enctype="multipart/form-data">
                    <?php wp_nonce_field('reconcile_position_import', 'reconcile_position_nonce'); ?>

                    <div class="reconcile-form-row">
                        <label for="reconcile-position-file">
                            <?php esc_html_e('Select spreadsheet file (.csv or .xlsx)', 'reconcile'); ?>
                        </label>
                        <input
                            type="file"
                            id="reconcile-position-file"
                            name="import_file"
                            accept=".csv,.xlsx"
                            required
                        />
                    </div>

                    <div class="reconcile-form-row">
                        <label>
                            <input type="checkbox" name="dry_run" id="reconcile-position-dry-run" value="1" checked />
                            <?php esc_html_e('Dry run (validate only, no changes will be made)', 'reconcile'); ?>
                        </label>
                    </div>

                    <div class="reconcile-form-row">
                        <button type="submit" class="button button-primary" id="reconcile-position-submit">
                            <?php esc_html_e('Import', 'reconcile'); ?>
                        </button>
                        <span class="spinner" id="reconcile-position-spinner"></span>
                    </div>
                </form>

                <div id="reconcile-position-results" class="reconcile-results" style="display:none;"></div>
            </div>

            <div class="reconcile-card" style="margin-top: 20px;">
                <h2><?php esc_html_e('Export Positions to CSV', 'reconcile'); ?></h2>
                <p class="description">
                    <?php esc_html_e(
                        'Download all positions as a CSV file. The export uses the same column format as the import, '
                        . 'so the exported file can be edited and re-imported.',
                        'reconcile'
                    ); ?>
                </p>

                <div class="reconcile-form-row">
                    <?php
                    $exportUrl = wp_nonce_url(
                        admin_url('admin-post.php?action=reconcile_position_export'),
                        'reconcile_position_export'
                    );
                    ?>
                    <a href="<?php echo esc_url($exportUrl); ?>" class="button button-secondary">
                        <?php esc_html_e('Export Positions', 'reconcile'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get per-property notes for the reference table.
     *
     * @return array<string, string> property => HTML note
     */
    private static function getPropertyNotes(): array
    {
        return [
            'position_id' => 'Numeric WordPress post ID of the existing position to update. '
                . 'If provided, the position is looked up by ID. If both ID and Name are provided, '
                . 'the position is found by ID and its name is updated. '
                . 'Either <strong>Position ID</strong> or <strong>Position Name</strong> must be supplied.',
            'position_name' => 'The full name of the position. Used to look up the position if no Position ID is provided. '
                . 'If both ID and Name are provided, the position name is updated. '
                . 'Either <strong>Position ID</strong> or <strong>Position Name</strong> must be supplied.',
            'email' => 'Optional. The position\'s dedicated email address.',
            'minimum_sobriety' => 'Optional. Minimum sobriety requirement in months (numeric value).',
            'term_years' => 'Optional. Term length in years (numeric value).',
            'short_description' => 'Optional. A short description of the position.',
            'summary' => 'Optional. A summary of the position\'s responsibilities.',
        ];
    }
}