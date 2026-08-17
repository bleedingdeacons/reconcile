<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use BleedingDeacons\WpMocks\TestCase;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Reconcile\Admin\PositionsAdmin;
use Reconcile\Position\PositionColumnMapper;

/**
 * Tests for the Position Import admin screen.
 *
 * Same three-part shape as {@see GroupsAdminTest}, which carries the fuller
 * explanation of why src/Admin is covered at all. The position screen differs
 * from the other two in one respect worth pinning down: it is the only import
 * that will *create* a record when the name matches nothing, so the screen has
 * to say so before anyone uploads a file.
 *
 * @covers \Reconcile\Admin\PositionsAdmin
 */
class PositionsAdminTest extends TestCase
{
    /** The admin_enqueue_scripts suffix WordPress gives this screen. */
    private const HOOK_SUFFIX = 'reconcile_page_reconcile-positions';

    private PositionsAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new PositionsAdmin();

        // wp_nonce_url() is the one WordPress function these screens call that
        // wp-mocks does not stub. See GroupsAdminTest for the reasoning.
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
                ['fn' => 'wp_enqueue_script', 'handle' => 'reconcile-position-admin'],
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
                'nonce'   => wp_create_nonce('reconcile_position_import'),
            ],
            WpState::$localized['reconcilePositionAdmin'] ?? null
        );
    }

    /**
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
            'members screen' => ['toplevel_page_reconcile'],
            'groups screen'  => ['reconcile_page_reconcile-groups'],
            'dashboard'      => ['index.php'],
            'plugins list'   => ['plugins.php'],
            'no screen'      => [''],
        ];
    }

    // --- rendering --------------------------------------------------------

    /** @test */
    public function render_page_heads_the_screen_and_both_cards(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('<h1>Reconcile — Position Import</h1>', $html);
        $this->assertStringContainsString('Import Positions from Spreadsheet', $html);
        $this->assertStringContainsString('Export Positions to CSV', $html);
    }

    /** @test */
    public function render_page_gives_every_accepted_header_a_row_of_its_own(): void
    {
        $html = $this->render();

        $properties = PositionColumnMapper::getAcceptedHeaders();

        // One row per property, plus the table's own header row.
        $this->assertSame(count($properties) + 1, substr_count($html, '<tr>'));
    }

    /** @test */
    public function render_page_documents_every_property_label_and_alias(): void
    {
        $html = $this->render();

        $labels = PositionColumnMapper::getPropertyLabels();

        foreach (PositionColumnMapper::getAcceptedHeaders() as $property => $aliases) {
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
     * Every position property carries a note, so the placeholder branch never
     * runs here — as on the group screen, and unlike the member one.
     *
     * @test
     */
    public function render_page_notes_every_property_so_no_placeholder_is_shown(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('reconcile-note-muted', $html);
    }

    /**
     * Positions are the only import that creates records, so the screen says
     * so twice: once in the card's description and once against the name
     * column.
     *
     * @test
     */
    public function render_page_warns_that_an_unmatched_name_creates_a_position(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('if no match is found, a new position is created', $html);
        $this->assertStringContainsString('If no existing position matches the name, a new position is created', $html);
    }

    /** @test */
    public function render_page_emits_the_upload_form_with_its_nonce(): void
    {
        $html = $this->render();

        $this->assertStringContainsString(
            '<form id="reconcile-position-import-form" enctype="multipart/form-data">',
            $html
        );
        $this->assertStringContainsString(
            '<input type="hidden" name="reconcile_position_nonce" value="'
                . wp_create_nonce('reconcile_position_import') . '" />',
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
            '<input type="checkbox" name="dry_run" id="reconcile-position-dry-run" value="1" checked />',
            $html
        );
    }

    /** @test */
    public function render_page_links_the_export_endpoint_with_a_nonce(): void
    {
        $html = $this->render();

        // esc_url(), not the raw string: WordPress encodes the separator as
        // &#038; in an href, so asserting on a bare & described output the
        // plugin has never produced. It only passed while the test double
        // returned its input untouched.
        $expected = esc_url(
            admin_url('admin-post.php?action=reconcile_position_export')
            . '&_wpnonce=' . wp_create_nonce('reconcile_position_export')
        );

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
