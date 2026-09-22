<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit\Import;

use function Brain\Monkey\Actions\expectDone;
use Mockery;
use BleedingDeacons\WpMocks\WpState;
use Reconcile\Group\GroupImporter;
use Unity\Contacts\Interfaces\Contact;
use Unity\Contacts\Interfaces\ContactFactory;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupFactory;
use Unity\Groups\Interfaces\GroupRepository;

/*
 * Tests for the unity/group_changing dispatch added to GroupImporter.
 *
 * These tests share the same hidden bootstrap as MemberImporterTest —
 * specifically, they assume ABSPATH is defined and core WP functions
 * (wp_insert_post, wp_update_post, do_action, update_post_meta,
 * is_wp_error, sanitize_text_field) have been stubbed by tests/bootstrap.php.
 *
 * If a do_action stub is not provided by the existing bootstrap, the
 * stub at the bottom of this file (under `namespace { ... }`) is loaded
 * once on first test run and captures unity/group_changing dispatches
 * into the static $dispatchedGroupChangingEvents array.
 */

covers(GroupImporter::class);

// ── Helpers ─────────────────────────────────────────────────────────

/**
 * @param array<int, array{0: string, 1: string, 2: string}> $contactRows
 */
function changingEventGroup(int $id, string $title, array $contactRows): Group
{
    $contacts = [];
    foreach ($contactRows as [$name, $email, $phone]) {
        $contacts[] = changingEventContact($name, $email, $phone);
    }

    $group = Mockery::mock(Group::class);
    $group->shouldReceive('getId')->andReturn($id);
    $group->shouldReceive('getTitle')->andReturn($title);
    $group->shouldReceive('getContacts')->andReturn($contacts);
    $group->shouldReceive('getMeetings')->andReturn([]);
    return $group;
}

function changingEventContact(string $name, string $email, string $phone): Contact
{
    $contact = Mockery::mock(Contact::class);
    $contact->shouldReceive('getName')->andReturn($name);
    $contact->shouldReceive('getEmail')->andReturn($email);
    $contact->shouldReceive('getPhone')->andReturn($phone);
    return $contact;
}

/**
 * Helper: write a CSV with all 12 standard columns and one row.
 *
 * @param array<int, string|int> $row
 */
function changingEventCsv(array $row): string
{
    return changingEventRawCsv(
        [
            'Group ID', 'Group Name', 'Group Email',
            'Contact 1 Name', 'Contact 1 Email', 'Contact 1 Phone',
            'Contact 2 Name', 'Contact 2 Email', 'Contact 2 Phone',
            'Contact 3 Name', 'Contact 3 Email', 'Contact 3 Phone',
        ],
        [$row]
    );
}

/**
 * @param array<int, string|int> $row
 */
function changingEventCsvNoId(array $row): string
{
    return changingEventRawCsv(
        [
            'Group ID', 'Group Name', 'Group Email',
            'Contact 1 Name', 'Contact 1 Email', 'Contact 1 Phone',
            'Contact 2 Name', 'Contact 2 Email', 'Contact 2 Phone',
            'Contact 3 Name', 'Contact 3 Email', 'Contact 3 Phone',
        ],
        [$row]
    );
}

/**
 * @param array<int, string> $headers
 * @param array<int, array<int, string|int>> $rows
 */
function changingEventRawCsv(array $headers, array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'group_import_test_') . '.csv';
    $handle = fopen($path, 'w');
    fputcsv($handle, $headers, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }
    fclose($handle);
    return $path;
}

beforeEach(function () {
    // Captured (updated, original) tuples from unity/group_changing dispatches.
    $this->dispatchedGroupChangingEvents = [];

    // GroupImporter fires other actions too (unity/member_import and
    // friends); this watches only the one under test, and lets any number
    // of calls through so the "no event" cases are assertions about an
    // empty capture rather than an unmet expectation.
    expectDone('unity/group_changing')
        ->zeroOrMoreTimes()
        ->whenHappen(function (mixed $updated = null, mixed $original = null): void {
            $this->dispatchedGroupChangingEvents[] = [$updated, $original];
        });

    $this->groupRepository = Mockery::mock(GroupRepository::class);
    $this->groupFactory    = Mockery::mock(GroupFactory::class);
    $this->contactFactory  = Mockery::mock(ContactFactory::class);

    $this->importer = new GroupImporter(
        $this->groupRepository,
        $this->groupFactory,
        $this->contactFactory
    );

    // Default contact-factory behaviour — return a Contact mock
    // that echoes whatever values were passed in.
    $this->contactFactory->shouldReceive('create')
        ->andReturnUsing(fn($name, $email, $phone) => changingEventContact($name, $email, $phone))
        ->byDefault();
});

it('dispatches unity/group_changing on update with the pre- and post-write state', function () {
    $postId = 7100;

    $existing = changingEventGroup($postId, 'My Group', [
        ['Alice', 'alice@example.com', '555-0001'],
    ]);

    $reread = changingEventGroup($postId, 'My Group', [
        ['Alice', 'alice@example.com', '555-0001'],
        ['Bob',   'bob@example.com',   '555-0002'],
    ]);

    // findById is called twice for an update with our patch:
    //  1. By findExistingGroup (lookup) → returns $existing
    //  2. By updateGroup after saveMetaFields (re-read) → returns $reread
    $this->groupRepository->shouldReceive('findById')
        ->with($postId)
        ->andReturnValues([$existing, $reread]);

    $path = changingEventCsv([$postId, 'My Group', '', 'Alice', 'alice@example.com', '555-0001', 'Bob', 'bob@example.com', '555-0002', '', '', '']);

    $result = $this->importer->import($path, dryRun: false);

    unlink($path);

    expect($result->getSkipped())->toBe(0, 'Row should not be skipped: ' . json_encode($result->getSkippedRows()))
        ->and($result->getUpdated())->toBe(1);

    expect($this->dispatchedGroupChangingEvents)->toHaveCount(1, 'Exactly one event should fire.');

    [$dispatchedUpdated, $dispatchedOriginal] = $this->dispatchedGroupChangingEvents[0];

    expect($dispatchedUpdated)->toBe($reread, 'First arg is the post-write re-read.')
        ->and($dispatchedOriginal)->toBe($existing, 'Second arg is the pre-write snapshot.');
});

it('does not dispatch unity/group_changing on a dry run', function () {
    $postId = 7200;

    $existing = changingEventGroup($postId, 'My Group', [
        ['Alice', 'alice@example.com', '555-0001'],
    ]);

    // Lookup only — no re-read on a dry run because no writes.
    $this->groupRepository->shouldReceive('findById')
        ->once()
        ->with($postId)
        ->andReturn($existing);

    $path = changingEventCsv([$postId, 'My Group', '', 'Bob', 'bob@example.com', '555-9999', '', '', '', '', '', '']);

    $result = $this->importer->import($path, dryRun: true);

    unlink($path);

    expect($result->getUpdated())->toBe(1)
        ->and($this->dispatchedGroupChangingEvents)->toBe([], 'No event on dry runs.');
});

it('does not dispatch unity/group_changing on the create path', function () {
    // Group ID column omitted entirely — the importer takes the
    // create branch via createGroupPost + saveNewGroup. saveNewGroup
    // intentionally does not fire unity/group_changing (creates are
    // summary-only).
    $this->groupRepository->shouldNotReceive('findById');

    // No existing groups by name.
    $this->groupRepository->shouldReceive('findAll')->andReturn([])->byDefault();

    $path = changingEventCsvNoId(['', 'Brand New Group', '', 'Charlie', 'c@example.com', '555-1111', '', '', '', '', '', '']);

    WpState::$nextPostId = 7300;

    $result = $this->importer->import($path, dryRun: false);

    unlink($path);

    expect($result->getCreated())->toBe(1)
        ->and($this->dispatchedGroupChangingEvents)->toBe([], 'No event on creates.');
});
