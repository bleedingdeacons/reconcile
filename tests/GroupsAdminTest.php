<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use function Brain\Monkey\Functions\when;
use BleedingDeacons\WpMocks\WpState;
use Reconcile\Admin\GroupsAdmin;
use Reconcile\Group\GroupColumnMapper;

/*
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
 */

covers(GroupsAdmin::class);

/** The admin_enqueue_scripts suffix WordPress gives this screen. */
const GROUPS_HOOK_SUFFIX = 'reconcile_page_reconcile-groups';

dataset('groups other screens', [
    'members screen'   => ['toplevel_page_reconcile'],
    'positions screen' => ['reconcile_page_reconcile-positions'],
    'dashboard'        => ['index.php'],
    'plugins list'     => ['plugins.php'],
    'no screen'        => [''],
]);

beforeEach(function () {
    $this->admin = new GroupsAdmin();

    // wp_nonce_url() is the one WordPress function these screens call that
    // wp-mocks does not stub. Stand in for it here, appending the nonce
    // the shared wp_create_nonce() stub would have produced so the
    // assertions below can name the same value.
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
    $this->admin->enqueueAssets(GROUPS_HOOK_SUFFIX);

    expect(WpState::$enqueued)->toBe([
        ['fn' => 'wp_enqueue_style', 'handle' => 'reconcile-admin'],
        ['fn' => 'wp_enqueue_script', 'handle' => 'reconcile-group-admin'],
    ]);
});

it('localises the AJAX endpoint and nonce', function () {
    $this->admin->enqueueAssets(GROUPS_HOOK_SUFFIX);

    expect(WpState::$localized['reconcileGroupAdmin'] ?? null)->toBe([
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('reconcile_group_import'),
    ]);
});

// The suffix guard is what keeps each import screen's JavaScript off the
// other two — and off the rest of wp-admin.
it('enqueues nothing on any other screen', function (string $hookSuffix) {
    $this->admin->enqueueAssets($hookSuffix);

    expect(WpState::$enqueued)->toBe([])
        ->and(WpState::$localized)->toBe([]);
})->with('groups other screens');

// --- rendering --------------------------------------------------------
it('heads the screen and both cards', function () {
    $html = ($this->render)();

    expect($html)->toContain('<h1>Reconcile — Group Import</h1>')
        ->toContain('Import Groups from Spreadsheet')
        ->toContain('Export Groups to CSV');
});

it('gives every accepted header a row of its own', function () {
    $html = ($this->render)();

    $properties = GroupColumnMapper::getAcceptedHeaders();

    // One row per property, plus the table's own header row.
    expect(substr_count($html, '<tr>'))->toBe(count($properties) + 1);
});

it('documents every property label and alias', function () {
    $html = ($this->render)();

    $labels = GroupColumnMapper::getPropertyLabels();

    foreach (GroupColumnMapper::getAcceptedHeaders() as $property => $aliases) {
        expect($labels)->toHaveKey($property, message: $property . ' has no label to render')
            ->and($html)->toContain('<strong>' . $labels[$property] . '</strong>');

        foreach ($aliases as $alias) {
            // Every accepted header alias must be listed on the screen.
            expect($html)->toContain('<code>' . $alias . '</code>');
        }
    }
});

// Unlike the member screen, every group property carries a note, so the
// placeholder branch never runs here.
it('notes every property so no placeholder is shown', function () {
    $html = ($this->render)();

    expect($html)->not->toContain('reconcile-note-muted')
        ->toContain('Either <strong>Group ID</strong> or <strong>Group Name</strong>');
});

it('emits the upload form with its nonce', function () {
    $html = ($this->render)();

    expect($html)->toContain('<form id="reconcile-group-import-form" enctype="multipart/form-data">')
        ->toContain('<input type="hidden" name="reconcile_group_nonce" value="' . wp_create_nonce('reconcile_group_import') . '" />')
        ->toContain('name="import_file"')
        ->toContain('accept=".csv,.xlsx"');
});

it('defaults the import to a dry run', function () {
    $html = ($this->render)();

    expect($html)->toContain('<input type="checkbox" name="dry_run" id="reconcile-group-dry-run" value="1" checked />');
});

it('links the export endpoint with a nonce', function () {
    $html = ($this->render)();

    // esc_url(), not the raw string: WordPress encodes the separator as
    // &#038; in an href, so asserting on a bare & described output the
    // plugin has never produced. It only passed while the test double
    // returned its input untouched.
    $expected = esc_url(
        admin_url('admin-post.php?action=reconcile_group_export')
        . '&_wpnonce=' . wp_create_nonce('reconcile_group_export')
    );

    expect($html)->toContain('href="' . $expected . '"');
});
