<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use BleedingDeacons\WpMocks\TestCase;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Reconcile\Admin\MembersAdmin;
use Reconcile\Member\MemberColumnMapper;
use Reconcile\Member\MemberImporter;
use ReflectionMethod;

/**
 * Tests for the Member Import admin screen — Reconcile's top-level page.
 *
 * Same three-part shape as {@see GroupsAdminTest}, which carries the fuller
 * explanation of why src/Admin is covered at all. The member screen is the one
 * worth reading closely, for two reasons:
 *
 *   - It is the only one of the three whose reference table exercises both
 *     sides of the "does this property have a note?" conditional: five of the
 *     eleven member properties are annotated and the rest fall through to the
 *     em-dash placeholder. The group and position screens annotate every
 *     property, so their placeholder branch is dead at runtime.
 *   - Two of its notes are built from MemberImporter's own constants rather
 *     than written out by hand — the recognised truthy values and the accepted
 *     rotation date formats. Asserting the rendered HTML against those
 *     constants is what stops the on-screen documentation drifting away from
 *     what the importer actually accepts.
 *
 * @covers \Reconcile\Admin\MembersAdmin
 */
class MembersAdminTest extends TestCase
{
    /** The admin_enqueue_scripts suffix WordPress gives this screen. */
    private const HOOK_SUFFIX = 'toplevel_page_reconcile';

    private MembersAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new MembersAdmin();

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

        // Style and script share the 'reconcile-admin' handle on this screen;
        // the other two register their script under a screen-specific one.
        $this->assertSame(
            [
                ['fn' => 'wp_enqueue_style', 'handle' => 'reconcile-admin'],
                ['fn' => 'wp_enqueue_script', 'handle' => 'reconcile-admin'],
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
                'nonce'   => wp_create_nonce('reconcile_import'),
            ],
            WpState::$localized['reconcileAdmin'] ?? null
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
            'groups screen'    => ['reconcile_page_reconcile-groups'],
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

        $this->assertStringContainsString('<h1>Reconcile — Member Import</h1>', $html);
        $this->assertStringContainsString('Import Members from Spreadsheet', $html);
        $this->assertStringContainsString('Export Members to CSV', $html);
    }

    /** @test */
    public function render_page_gives_every_accepted_header_a_row_of_its_own(): void
    {
        $html = $this->render();

        $properties = MemberColumnMapper::getAcceptedHeaders();

        // One row per property, plus the table's own header row.
        $this->assertSame(count($properties) + 1, substr_count($html, '<tr>'));
    }

    /** @test */
    public function render_page_documents_every_property_label_and_alias(): void
    {
        $html = $this->render();

        $labels = MemberColumnMapper::getPropertyLabels();

        foreach (MemberColumnMapper::getAcceptedHeaders() as $property => $aliases) {
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
     * The properties with nothing to say get an em-dash rather than an empty
     * cell — one per unannotated property, and no more.
     *
     * @test
     */
    public function render_page_places_a_dash_against_every_unannotated_property(): void
    {
        $html = $this->render();

        $properties = array_keys(MemberColumnMapper::getAcceptedHeaders());
        $annotated  = array_intersect($properties, array_keys($this->propertyNotes()));

        $this->assertNotEmpty($annotated, 'expected at least one annotated property');
        $this->assertNotCount(count($properties), $annotated, 'expected at least one unannotated property');

        $this->assertSame(
            count($properties) - count($annotated),
            substr_count($html, '<span class="reconcile-note-muted">—</span>')
        );
    }

    /**
     * The GSR note is generated from the importer's truthy list, so the screen
     * cannot document a value the importer does not accept, or miss one it
     * does.
     *
     * @test
     */
    public function render_page_documents_the_truthy_values_the_importer_recognises(): void
    {
        $html = $this->render();

        foreach (MemberImporter::getTruthyValues() as $value) {
            $this->assertStringContainsString(
                '<code>' . $value . '</code>',
                $html,
                'truthy value "' . $value . '" is accepted by the importer but not documented'
            );
        }
    }

    /**
     * Likewise the rotation note and the accepted date formats.
     *
     * @test
     */
    public function render_page_documents_the_date_formats_the_importer_accepts(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('Accepted date formats:', $html);

        foreach (MemberImporter::getAcceptedDateFormats() as $format) {
            $this->assertStringContainsString(
                '<code>' . $format . '</code>',
                $html,
                'date format "' . $format . '" is accepted by the importer but not documented'
            );
        }
    }

    /** @test */
    public function render_page_emits_the_upload_form_with_its_nonce(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('<form id="reconcile-import-form" enctype="multipart/form-data">', $html);
        $this->assertStringContainsString(
            '<input type="hidden" name="reconcile_nonce" value="' . wp_create_nonce('reconcile_import') . '" />',
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
            '<input type="checkbox" name="dry_run" id="reconcile-dry-run" value="1" checked />',
            $html
        );
    }

    /** @test */
    public function render_page_links_the_export_endpoint_with_a_nonce(): void
    {
        $html = $this->render();

        $expected = admin_url('admin-post.php?action=reconcile_member_export')
            . '&_wpnonce=' . wp_create_nonce('reconcile_member_export');

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

    /**
     * The screen's per-property notes, read from the private static that
     * builds them so the placeholder count above stays correct when a note is
     * added or removed.
     *
     * @return array<string, string>
     */
    private function propertyNotes(): array
    {
        /** @var array<string, string> $notes */
        $notes = (new ReflectionMethod(MembersAdmin::class, 'getPropertyNotes'))->invoke(null);

        return $notes;
    }
}
