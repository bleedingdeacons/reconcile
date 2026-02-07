<?php

declare(strict_types=1);

namespace Reconcile\Admin;

use Reconcile\Import\ColumnMapper;
use Reconcile\Import\MemberImporter;

/**
 * Admin Page
 *
 * Registers a submenu page under "Intergroup" where administrators
 * can upload a spreadsheet to import member data.
 */
class MemberImportAdmin
{
    /**
     * Register the admin page and assets.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * Add the submenu page under Intergroup.
     */
    public function addMenuPage(): void
    {
        add_submenu_page(
                'intergroup',
                __('Reconcile — Member Import', 'reconcile'),
                __('Import', 'reconcile'),
                'manage_options',
                'reconcile',
                [$this, 'renderPage']
        );
    }

    /**
     * Enqueue admin CSS and JS only on our page.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'intergroup_page_reconcile') {
            return;
        }

        wp_enqueue_style(
                'reconcile-admin',
                RECONCILE_URL . 'assets/css/admin.css',
                [],
                RECONCILE_VERSION
        );

        wp_enqueue_script(
                'reconcile-admin',
                RECONCILE_URL . 'assets/js/admin.js',
                ['jquery'],
                RECONCILE_VERSION,
                true
        );

        wp_localize_script('reconcile-admin', 'reconcileAdmin', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('reconcile_import'),
        ]);
    }

    /**
     * Render the admin page.
     */
    public function renderPage(): void
    {
        $acceptedHeaders = ColumnMapper::getAcceptedHeaders();
        $labels = ColumnMapper::getPropertyLabels();
        $notes = self::getPropertyNotes();
        $truthyValues = MemberImporter::getTruthyValues();

        ?>
        <div class="wrap reconcile-wrap">
            <h1><?php esc_html_e('Reconcile — Member Import', 'reconcile'); ?></h1>

            <div class="reconcile-card">
                <h2><?php esc_html_e('Import Members from Spreadsheet', 'reconcile'); ?></h2>
                <p class="description">
                    <?php esc_html_e(
                            'Upload a .csv or .xlsx file with member data. The first row must contain column headers '
                            . 'that match the expected property names. The Home Group and Intergroup Position columns '
                            . 'should contain the name as text — they will be automatically matched to the corresponding '
                            . 'WordPress posts.',
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

                <form id="reconcile-import-form" enctype="multipart/form-data">
                    <?php wp_nonce_field('reconcile_import', 'reconcile_nonce'); ?>

                    <div class="reconcile-form-row">
                        <label for="reconcile-file">
                            <?php esc_html_e('Select spreadsheet file (.csv or .xlsx)', 'reconcile'); ?>
                        </label>
                        <input
                                type="file"
                                id="reconcile-file"
                                name="import_file"
                                accept=".csv,.xlsx"
                                required
                        />
                    </div>

                    <div class="reconcile-form-row">
                        <label>
                            <input type="checkbox" name="dry_run" id="reconcile-dry-run" value="1" checked />
                            <?php esc_html_e('Dry run (validate only, no changes will be made)', 'reconcile'); ?>
                        </label>
                    </div>

                    <div class="reconcile-form-row">
                        <button type="submit" class="button button-primary" id="reconcile-submit">
                            <?php esc_html_e('Import', 'reconcile'); ?>
                        </button>
                        <span class="spinner" id="reconcile-spinner"></span>
                    </div>
                </form>

                <div id="reconcile-results" class="reconcile-results" style="display:none;"></div>
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
        $truthyValues = MemberImporter::getTruthyValues();
        $truthyCodes = array_map(
                fn($v) => '<code>' . esc_html($v) . '</code>',
                $truthyValues
        );

        $dateFormats = MemberImporter::getAcceptedDateFormats();
        $dateCodes = array_map(
                fn($f) => '<code>' . esc_html($f) . '</code>',
                $dateFormats
        );

        return [
                'home_group' => 'Text value — looked up against existing group titles to resolve the post ID.',
                'intergroup_position' => 'Text value — looked up against existing position names to resolve the post ID.',
                'intergroup_position_rotation' => 'Required when an Intergroup Position is specified. '
                        . 'Accepted date formats: ' . implode(', ', $dateCodes)
                        . ' (separators <code>/</code> <code>-</code> <code>.</code> all accepted). '
                        . 'Normalised to <code>yyyy-MM-dd</code> on import. '
                        . 'Row is <strong>skipped</strong> if position is set and rotation is missing or invalid.',
                'is_gsr' => 'Boolean — recognised as <strong>true</strong>: '
                        . implode(', ', $truthyCodes)
                        . '. Everything else is treated as <strong>false</strong>.',
        ];
    }
}