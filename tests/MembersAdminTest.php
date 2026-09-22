<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use function Brain\Monkey\Functions\when;
use BleedingDeacons\WpMocks\WpState;
use Reconcile\Admin\MembersAdmin;
use Reconcile\Member\MemberColumnMapper;
use Reconcile\Member\MemberImporter;
use ReflectionMethod;

/*
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
 */

covers(MembersAdmin::class);

/** The admin_enqueue_scripts suffix WordPress gives this screen. */
const MEMBERS_HOOK_SUFFIX = 'toplevel_page_reconcile';

/**
 * The screen's per-property notes, read from the private static that
 * builds them so the placeholder count above stays correct when a note is
 * added or removed.
 *
 * @return array<string, string>
 */
function membersAdminPropertyNotes(): array
{
    /** @var array<string, string> $notes */
    $notes = (new ReflectionMethod(MembersAdmin::class, 'getPropertyNotes'))->invoke(null);

    return $notes;
}

dataset('members other screens', [
    'groups screen'    => ['reconcile_page_reconcile-groups'],
    'positions screen' => ['reconcile_page_reconcile-positions'],
    'dashboard'        => ['index.php'],
    'plugins list'     => ['plugins.php'],
    'no screen'        => [''],
]);

beforeEach(function () {
    $this->admin = new MembersAdmin();

    // wp_nonce_url() is the one WordPress function these screens call that
    // wp-mocks does not stub. See GroupsAdminTest for the reasoning.
    when('wp_nonce_url')->alias(
        static fn(string $url, string $action = '-1', string $name = '_wpnonce'): string
            => $url . '&' . $name . '=' . wp_create_nonce($action)
    );

    // Run renderPage() for real and hand back what it echoed.
    $this->render = fn (): string => captureOutput(fn () => $this->admin->renderPage());
});

// --- registration -----------------------------------------------------
it('hooks asset enqueuing on register()', function () {
    $this->admin->register();

    $this->assertActionAdded(
        'admin_enqueue_scripts',
        [$this->admin, 'enqueueAssets'],
        'expected register() to hook enqueueAssets() to admin_enqueue_scripts'
    );
});

it('enqueues the screen style and script', function () {
    $this->admin->enqueueAssets(MEMBERS_HOOK_SUFFIX);

    // Style and script share the 'reconcile-admin' handle on this screen;
    // the other two register their script under a screen-specific one.
    expect(WpState::$enqueued)->toBe([
        ['fn' => 'wp_enqueue_style', 'handle' => 'reconcile-admin'],
        ['fn' => 'wp_enqueue_script', 'handle' => 'reconcile-admin'],
    ]);
});

it('localises the AJAX endpoint and nonce', function () {
    $this->admin->enqueueAssets(MEMBERS_HOOK_SUFFIX);

    expect(WpState::$localized['reconcileAdmin'] ?? null)->toBe([
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('reconcile_import'),
    ]);
});

it('enqueues nothing on any other screen', function (string $hookSuffix) {
    $this->admin->enqueueAssets($hookSuffix);

    expect(WpState::$enqueued)->toBe([])
        ->and(WpState::$localized)->toBe([]);
})->with('members other screens');

// --- rendering --------------------------------------------------------
it('heads the screen and both cards', function () {
    $html = ($this->render)();

    expect($html)->toContain('<h1>Reconcile — Member Import</h1>')
        ->toContain('Import Members from Spreadsheet')
        ->toContain('Export Members to CSV');
});

it('gives every accepted header a row of its own', function () {
    $html = ($this->render)();

    $properties = MemberColumnMapper::getAcceptedHeaders();

    // One row per property, plus the table's own header row.
    expect(substr_count($html, '<tr>'))->toBe(count($properties) + 1);
});

it('documents every property label and alias', function () {
    $html = ($this->render)();

    $labels = MemberColumnMapper::getPropertyLabels();

    foreach (MemberColumnMapper::getAcceptedHeaders() as $property => $aliases) {
        expect($labels)->toHaveKey($property, message: $property . ' has no label to render')
            ->and($html)->toContain('<strong>' . $labels[$property] . '</strong>');

        foreach ($aliases as $alias) {
            // Every accepted header alias must be listed on the screen.
            expect($html)->toContain('<code>' . $alias . '</code>');
        }
    }
});

// The properties with nothing to say get an em-dash rather than an empty
// cell — one per unannotated property, and no more.
it('places a dash against every unannotated property', function () {
    $html = ($this->render)();

    $properties = array_keys(MemberColumnMapper::getAcceptedHeaders());
    $annotated  = array_intersect($properties, array_keys(membersAdminPropertyNotes()));

    expect($annotated)->not->toBeEmpty('expected at least one annotated property');
    expect($annotated)->not->toHaveCount(count($properties), 'expected at least one unannotated property');

    expect(substr_count($html, '<span class="reconcile-note-muted">—</span>'))->toBe(count($properties) - count($annotated));
});

// The GSR note is generated from the importer's truthy list, so the screen
// cannot document a value the importer does not accept, or miss one it
// does.
it('documents the truthy values the importer recognises', function () {
    $html = ($this->render)();

    foreach (MemberImporter::getTruthyValues() as $value) {
        // Every truthy value the importer accepts must be documented.
        expect($html)->toContain('<code>' . $value . '</code>');
    }
});

// Likewise the rotation note and the accepted date formats.
it('documents the date formats the importer accepts', function () {
    $html = ($this->render)();

    expect($html)->toContain('Accepted date formats:');

    foreach (MemberImporter::getAcceptedDateFormats() as $format) {
        // Every date format the importer accepts must be documented.
        expect($html)->toContain('<code>' . $format . '</code>');
    }
});

it('emits the upload form with its nonce', function () {
    $html = ($this->render)();

    expect($html)->toContain('<form id="reconcile-import-form" enctype="multipart/form-data">')
        ->toContain('<input type="hidden" name="reconcile_nonce" value="' . wp_create_nonce('reconcile_import') . '" />')
        ->toContain('name="import_file"')
        ->toContain('accept=".csv,.xlsx"');
});

it('defaults the import to a dry run', function () {
    $html = ($this->render)();

    expect($html)->toContain('<input type="checkbox" name="dry_run" id="reconcile-dry-run" value="1" checked />');
});

it('links the export endpoint with a nonce', function () {
    $html = ($this->render)();

    // esc_url(), not the raw string: WordPress encodes the separator as
    // &#038; in an href, so asserting on a bare & described output the
    // plugin has never produced. It only passed while the test double
    // returned its input untouched.
    $expected = esc_url(
        admin_url('admin-post.php?action=reconcile_member_export')
        . '&_wpnonce=' . wp_create_nonce('reconcile_member_export')
    );

    expect($html)->toContain('href="' . $expected . '"');
});
