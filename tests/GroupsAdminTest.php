<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use BleedingDeacons\WpMocks\TestCase;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Reconcile\Admin\GroupsAdmin;
use Reconcile\Group\GroupColumnMapper;

/**
 * Tests for the Group Import admin screen.
 *
 * src/Admin was excluded from the coverage source set until now, on the
 * grounds that admin screens are "render/callback glue exercised through
 * WordPress at runtime". Amber covers its whole src/Admin on this same
 * tooling, and Integrity has since followed, so the exclusion was habit
 * rather than necessity.
 *
 * Reconcile's admin layer really is thin — the upload handling, batching and
 * spreadsheet parsing all live in src/Core and the src/{Group,Member,Position}
 * handlers, which were never excluded and are covered elsewhere. What is left
 * here is two kinds of method, each with its own technique:
 *
 *   - register() and enqueueAssets() are run for real and asserted against
 *     WpState, which records what was hooked, enqueued and localised. The
 *     screen-suffix guard is the only branch either has, so it is driven both
 *     ways — including with the *other* two screens' suffixes, which is what
 *     stops one screen loading another's JavaScript.
 *   - renderPage() is called inside an output buffer and asserted on as HTML.
 *     Nothing is mocked out of the way: the reference table really is built
 *     from GroupColumnMapper, so these assertions fail if a property is added
 *     to the mapper and left undocumented on the screen.
 *
 * Nothing here calls wp_die(), wp_redirect() or wp_send_json_*, so none of the
 * exception/exit handling those need applies.
 *
 * @covers \Reconcile\Admin\GroupsAdmin
 */
class GroupsAdminTest extends TestCase
{
    /** The admin_enqueue_scripts suffix WordPress gives this screen. */
    private const HOOK_SUFFIX = 'reconcile_page_reconcile-groups';

    private GroupsAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new GroupsAdmin();

        // wp_nonce_url() is the one WordPress function these screens call that
        // wp-mocks does not stub. Stand in for it here, appending the nonce
        // the shared wp_create_nonce() stub would have produced so the
        // assertions below can name the same value.
        Functions\when('wp_nonce_url')->alias(
            static fn(string $url, string $action = '-1', string $name = '_wpnonce'): string
                => $url . '&' . $name . '=' . wp_create_nonce($action)
        );
    }

    // --- registration -----------------------------------------------------

    /** @test */
    public function register_hooks_asset_enqueuing(): void
    {
        $this->admin->register();

        $this->assertActionAdded(
            'admin_enqueue_scripts',
            [$this->admin, 'enqueueAssets'],
            'expected register() to hook enqueueAssets() to admin_enqueue_scripts'
        );
    }

    /** @test */
    public function enqueue_assets_registers_the_screen_style_and_script(): void
    {
        $this->admin->enqueueAssets(self::HOOK_SUFFIX);

        $this->assertSame(
            [
                ['fn' => 'wp_enqueue_style', 'handle' => 'reconcile-admin'],
                ['fn' => 'wp_enqueue_script', 'handle' => 'reconcile-group-admin'],
            ],
            WpState::$enqueued
        );
    }

    /** @test */
    public function enqueue_assets_localises_the_ajax_endpoint_and_nonce(): void
    {
        $this->admin->enqueueAssets(self::HOOK_SUFFIX);

        $this->assertSame(
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('reconcile_group_import'),
            ],
            WpState::$localized['reconcileGroupAdmin'] ?? null
        );
    }

    /**
     * The suffix guard is what keeps each import screen's JavaScript off the
     * other two — and off the rest of wp-admin.
     *
     * @test
     * @dataProvider otherScreenProvider
     */
    public function enqueue_assets_does_nothing_on_any_other_screen(string $hookSuffix): void
    {
        $this->admin->enqueueAssets($hookSuffix);

        $this->assertSame([], WpState::$enqueued);
        $this->assertSame([], WpState::$localized);
    }

    /** @return array<string, array{0: string}> */
    public static function otherScreenProvider(): array
    {
        return [
            'members screen'   => ['toplevel_page_reconcile'],
            'positions screen' => ['reconcile_page_reconcile-positions'],
            'dashboard'        => ['index.php'],
            'plugins list'     => ['plugins.php'],
            'no screen'        => [''],
        ];
    }

    // --- rendering --------------------------------------------------------

    /** @test */
    public function render_page_heads_the_screen_and_both_cards(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('<h1>Reconcile — Group Import</h1>', $html);
        $this->assertStringContainsString('Import Groups from Spreadsheet', $html);
        $this->assertStringContainsString('Export Groups to CSV', $html);
    }

    /** @test */
    public function render_page_gives_every_accepted_header_a_row_of_its_own(): void
    {
        $html = $this->render();

        $properties = GroupColumnMapper::getAcceptedHeaders();

        // One row per property, plus the table's own header row.
        $this->assertSame(count($properties) + 1, substr_count($html, '<tr>'));
    }

    /** @test */
    public function render_page_documents_every_property_label_and_alias(): void
    {
        $html = $this->render();

        $labels = GroupColumnMapper::getPropertyLabels();

        foreach (GroupColumnMapper::getAcceptedHeaders() as $property => $aliases) {
            $this->assertArrayHasKey($property, $labels, $property . ' has no label to render');
            $this->assertStringContainsString('<strong>' . $labels[$property] . '</strong>', $html);

            foreach ($aliases as $alias) {
                $this->assertStringContainsString(
                    '<code>' . $alias . '</code>',
                    $html,
                    'accepted header "' . $alias . '" is not listed on the screen'
                );
            }
        }
    }

    /**
     * Unlike the member screen, every group property carries a note, so the
     * placeholder branch never runs here.
     *
     * @test
     */
    public function render_page_notes_every_property_so_no_placeholder_is_shown(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('reconcile-note-muted', $html);
        $this->assertStringContainsString('Either <strong>Group ID</strong> or <strong>Group Name</strong>', $html);
    }

    /** @test */
    public function render_page_emits_the_upload_form_with_its_nonce(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('<form id="reconcile-group-import-form" enctype="multipart/form-data">', $html);
        $this->assertStringContainsString(
            '<input type="hidden" name="reconcile_group_nonce" value="' . wp_create_nonce('reconcile_group_import') . '" />',
            $html
        );
        $this->assertStringContainsString('name="import_file"', $html);
        $this->assertStringContainsString('accept=".csv,.xlsx"', $html);
    }

    /** @test */
    public function render_page_defaults_the_import_to_a_dry_run(): void
    {
        $html = $this->render();

        $this->assertStringContainsString(
            '<input type="checkbox" name="dry_run" id="reconcile-group-dry-run" value="1" checked />',
            $html
        );
    }

    /** @test */
    public function render_page_links_the_export_endpoint_with_a_nonce(): void
    {
        $html = $this->render();

        $expected = admin_url('admin-post.php?action=reconcile_group_export')
            . '&_wpnonce=' . wp_create_nonce('reconcile_group_export');

        $this->assertStringContainsString('href="' . $expected . '"', $html);
    }

    /**
     * Run renderPage() for real and hand back what it echoed.
     */
    private function render(): string
    {
        ob_start();
        $this->admin->renderPage();

        return (string) ob_get_clean();
    }
}
