<?php

declare(strict_types=1);

namespace Reconcile\Admin;

use Reconcile\Import\GroupColumnMapper;
use Reconcile\Import\GroupImporter;

/**
 * Group Import Admin Page
 *
 * Renders the Group Import admin page and enqueues its assets.
 * Menu registration is handled by the Plugin class to ensure correct timing.
 */
class GroupsAdmin
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
        if ($hookSuffix !== 'reconcile_page_reconcile-groups') {
            return;
        }

        wp_enqueue_style(
            'reconcile-admin',
            RECONCILE_URL . 'assets/css/admin.css',
            [],
            RECONCILE_VERSION
        );

        wp_enqueue_script(
            'reconcile-group-admin',
            RECONCILE_URL . 'assets/js/group-admin.js',
            ['jquery'],
            RECONCILE_VERSION,
            true
        );

        wp_localize_script('reconcile-group-admin', 'reconcileGroupAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('reconcile_group_import'),
        ]);
    }

    /**
     * Render the admin page.
     */
    public function renderPage(): void
    {
        $acceptedHeaders = GroupColumnMapper::getAcceptedHeaders();
        $labels = GroupColumnMapper::getPropertyLabels();
        $notes = self::getPropertyNotes();
        $truthyValues = GroupImporter::getTruthyValues();

        ?>
        <div class="wrap reconcile-wrap">
            <h1><?php esc_html_e('Reconcile — Group Import', 'reconcile'); ?></h1>

            <div class="reconcile-card">
                <h2><?php esc_html_e('Import Groups from Spreadsheet', 'reconcile'); ?></h2>
                <p class="description">
                    <?php esc_html_e(
                        'Upload a .csv or .xlsx file with group data. The first row must contain column headers '
                        . 'that match the expected property names. Each row must include a Group ID matching an '
                        . 'existing group — the group will be updated with the imported data. '
                        . 'Up to 3 contacts can be specified per group, each with a name, email address, and '
                        . 'telephone number.',
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

                <form id="reconcile-group-import-form" enctype="multipart/form-data">
                    <?php wp_nonce_field('reconcile_group_import', 'reconcile_group_nonce'); ?>

                    <div class="reconcile-form-row">
                        <label for="reconcile-group-file">
                            <?php esc_html_e('Select spreadsheet file (.csv or .xlsx)', 'reconcile'); ?>
                        </label>
                        <input
                                type="file"
                                id="reconcile-group-file"
                                name="import_file"
                                accept=".csv,.xlsx"
                                required
                        />
                    </div>

                    <div class="reconcile-form-row">
                        <label>
                            <input type="checkbox" name="dry_run" id="reconcile-group-dry-run" value="1" checked />
                            <?php esc_html_e('Dry run (validate only, no changes will be made)', 'reconcile'); ?>
                        </label>
                    </div>

                    <div class="reconcile-form-row">
                        <button type="submit" class="button button-primary" id="reconcile-group-submit">
                            <?php esc_html_e('Import', 'reconcile'); ?>
                        </button>
                        <span class="spinner" id="reconcile-group-spinner"></span>
                    </div>
                </form>

                <div id="reconcile-group-results" class="reconcile-results" style="display:none;"></div>
            </div>

            <div class="reconcile-card" style="margin-top: 20px;">
                <h2><?php esc_html_e('Export Groups to CSV', 'reconcile'); ?></h2>
                <p class="description">
                    <?php esc_html_e(
                        'Download all groups as a CSV file. The export uses the same column format as the import, '
                        . 'so the exported file can be edited and re-imported.',
                        'reconcile'
                    ); ?>
                </p>

                <div class="reconcile-form-row">
                    <?php
                    $exportUrl = wp_nonce_url(
                        admin_url('admin-post.php?action=reconcile_group_export'),
                        'reconcile_group_export'
                    );
                    ?>
                    <a href="<?php echo esc_url($exportUrl); ?>" class="button button-secondary">
                        <?php esc_html_e('Export Groups', 'reconcile'); ?>
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
        $truthyValues = GroupImporter::getTruthyValues();
        $truthyCodes = array_map(
            fn($v) => '<code>' . esc_html($v) . '</code>',
            $truthyValues
        );

        return [
            'group_id' => '<strong>Required.</strong> Numeric WordPress post ID of the existing group to update. '
                . 'Row is <strong>skipped</strong> if the ID does not match an existing group.',
            'group_name' => 'Optional. If provided, the group title will be updated.',
            'group_email' => 'The group\'s dedicated email address.',
            'group_email_active' => 'Boolean — recognised as <strong>true</strong>: '
                . implode(', ', $truthyCodes)
                . '. Everything else is treated as <strong>false</strong>.',
            'contact_1_name' => 'Optional. Name of the first contact person.',
            'contact_1_email' => 'Optional. Email address of the first contact person.',
            'contact_1_phone' => 'Optional. Telephone number of the first contact person.',
            'contact_2_name' => 'Optional. Name of the second contact person.',
            'contact_2_email' => 'Optional. Email address of the second contact person.',
            'contact_2_phone' => 'Optional. Telephone number of the second contact person.',
            'contact_3_name' => 'Optional. Name of the third contact person.',
            'contact_3_email' => 'Optional. Email address of the third contact person.',
            'contact_3_phone' => 'Optional. Telephone number of the third contact person.',
        ];
    }
}
