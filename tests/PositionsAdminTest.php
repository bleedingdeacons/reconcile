<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use function Brain\Monkey\Functions\when;
use BleedingDeacons\WpMocks\WpState;
use Reconcile\Admin\PositionsAdmin;
use Reconcile\Position\PositionColumnMapper;

/*
 * Tests for the Position Import admin screen.
 *
 * Same three-part shape as {@see GroupsAdminTest}, which carries the fuller
 * explanation of why src/Admin is covered at all. The position screen differs
 * from the other two in one respect worth pinning down: it is the only import
 * that will *create* a record when the name matches nothing, so the screen has
 * to say so before anyone uploads a file.
 */

covers(PositionsAdmin::class);

/** The admin_enqueue_scripts suffix WordPress gives this screen. */
const POSITIONS_HOOK_SUFFIX = 'reconcile_page_reconcile-positions';

dataset('positions other screens', [
    'members screen' => ['toplevel_page_reconcile'],
    'groups screen'  => ['reconcile_page_reconcile-groups'],
    'dashboard'      => ['index.php'],
    'plugins list'   => ['plugins.php'],
    'no screen'      => [''],
]);

beforeEach(function () {
    $this->admin = new PositionsAdmin();

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
    $this->admin->enqueueAssets(POSITIONS_HOOK_SUFFIX);

    expect(WpState::$enqueued)->toBe([
        ['fn' => 'wp_enqueue_style', 'handle' => 'reconcile-admin'],
        ['fn' => 'wp_enqueue_script', 'handle' => 'reconcile-position-admin'],
    ]);
});

it('localises the AJAX endpoint and nonce', function () {
    $this->admin->enqueueAssets(POSITIONS_HOOK_SUFFIX);

    expect(WpState::$localized['reconcilePositionAdmin'] ?? null)->toBe([
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('reconcile_position_import'),
    ]);
});

it('enqueues nothing on any other screen', function (string $hookSuffix) {
    $this->admin->enqueueAssets($hookSuffix);

    expect(WpState::$enqueued)->toBe([])
        ->and(WpState::$localized)->toBe([]);
})->with('positions other screens');

// --- rendering --------------------------------------------------------
it('heads the screen and both cards', function () {
    $html = ($this->render)();

    expect($html)->toContain('<h1>Reconcile — Position Import</h1>')
        ->toContain('Import Positions from Spreadsheet')
        ->toContain('Export Positions to CSV');
});

it('gives every accepted header a row of its own', function () {
    $html = ($this->render)();

    $properties = PositionColumnMapper::getAcceptedHeaders();

    // One row per property, plus the table's own header row.
    expect(substr_count($html, '<tr>'))->toBe(count($properties) + 1);
});

it('documents every property label and alias', function () {
    $html = ($this->render)();

    $labels = PositionColumnMapper::getPropertyLabels();

    foreach (PositionColumnMapper::getAcceptedHeaders() as $property => $aliases) {
        expect($labels)->toHaveKey($property, message: $property . ' has no label to render')
            ->and($html)->toContain('<strong>' . $labels[$property] . '</strong>');

        foreach ($aliases as $alias) {
            // Every accepted header alias must be listed on the screen.
            expect($html)->toContain('<code>' . $alias . '</code>');
        }
    }
});

// Every position property carries a note, so the placeholder branch never
// runs here — as on the group screen, and unlike the member one.
it('notes every property so no placeholder is shown', function () {
    $html = ($this->render)();

    expect($html)->not->toContain('reconcile-note-muted');
});

// Positions are the only import that creates records, so the screen says
// so twice: once in the card's description and once against the name
// column.
it('warns that an unmatched name creates a position', function () {
    $html = ($this->render)();

    expect($html)->toContain('if no match is found, a new position is created')
        ->toContain('If no existing position matches the name, a new position is created');
});

it('emits the upload form with its nonce', function () {
    $html = ($this->render)();

    expect($html)->toContain('<form id="reconcile-position-import-form" enctype="multipart/form-data">')
        ->toContain('<input type="hidden" name="reconcile_position_nonce" value="'
            . wp_create_nonce('reconcile_position_import') . '" />')
        ->toContain('name="import_file"')
        ->toContain('accept=".csv,.xlsx"');
});

it('defaults the import to a dry run', function () {
    $html = ($this->render)();

    expect($html)->toContain('<input type="checkbox" name="dry_run" id="reconcile-position-dry-run" value="1" checked />');
});

it('links the export endpoint with a nonce', function () {
    $html = ($this->render)();

    // esc_url(), not the raw string: WordPress encodes the separator as
    // &#038; in an href, so asserting on a bare & described output the
    // plugin has never produced. It only passed while the test double
    // returned its input untouched.
    $expected = esc_url(
        admin_url('admin-post.php?action=reconcile_position_export')
        . '&_wpnonce=' . wp_create_nonce('reconcile_position_export')
    );

    expect($html)->toContain('href="' . $expected . '"');
});
