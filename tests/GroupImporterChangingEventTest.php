<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit\Import;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Reconcile\Group\GroupImporter;
use Unity\Contacts\Interfaces\Contact;
use Unity\Contacts\Interfaces\ContactFactory;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupFactory;
use Unity\Groups\Interfaces\GroupRepository;

/**
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
 *
 * @covers \Reconcile\Group\GroupImporter
 */
class GroupImporterChangingEventTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * Captured (updated, original) tuples from do_action calls.
     *
     * @var array<int, array{0: mixed, 1: mixed}>
     */
    public static array $dispatchedGroupChangingEvents = [];

    /** @var GroupRepository&Mockery\MockInterface */
    private $groupRepository;

    /** @var GroupFactory&Mockery\MockInterface */
    private $groupFactory;

    /** @var ContactFactory&Mockery\MockInterface */
    private $contactFactory;

    private GroupImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        self::$dispatchedGroupChangingEvents = [];

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
            ->andReturnUsing(fn($name, $email, $phone) => $this->makeContact($name, $email, $phone))
            ->byDefault();
    }

    /**
     * @test
     */
    public function update_dispatches_group_changing_with_pre_and_post_write_state(): void
    {
        $postId = 7100;

        $existing = $this->makeGroup($postId, 'My Group', [
            ['Alice', 'alice@example.com', '555-0001'],
        ]);

        $reread = $this->makeGroup($postId, 'My Group', [
            ['Alice', 'alice@example.com', '555-0001'],
            ['Bob',   'bob@example.com',   '555-0002'],
        ]);

        // findById is called twice for an update with our patch:
        //  1. By findExistingGroup (lookup) → returns $existing
        //  2. By updateGroup after saveMetaFields (re-read) → returns $reread
        $this->groupRepository->shouldReceive('findById')
            ->with($postId)
            ->andReturnValues([$existing, $reread]);

        $path = $this->writeCsv([$postId, 'My Group', '', 'Alice', 'alice@example.com', '555-0001', 'Bob', 'bob@example.com', '555-0002', '', '', '']);

        $result = $this->importer->import($path, dryRun: false);

        unlink($path);

        $this->assertSame(0, $result->getSkipped(), 'Row should not be skipped: ' . json_encode($result->getSkippedRows()));
        $this->assertSame(1, $result->getUpdated());

        $this->assertCount(1, self::$dispatchedGroupChangingEvents, 'Exactly one event should fire.');

        [$dispatchedUpdated, $dispatchedOriginal] = self::$dispatchedGroupChangingEvents[0];

        $this->assertSame($reread,   $dispatchedUpdated, 'First arg is the post-write re-read.');
        $this->assertSame($existing, $dispatchedOriginal, 'Second arg is the pre-write snapshot.');
    }

    /**
     * @test
     */
    public function dry_run_does_not_dispatch_group_changing(): void
    {
        $postId = 7200;

        $existing = $this->makeGroup($postId, 'My Group', [
            ['Alice', 'alice@example.com', '555-0001'],
        ]);

        // Lookup only — no re-read on a dry run because no writes.
        $this->groupRepository->shouldReceive('findById')
            ->once()
            ->with($postId)
            ->andReturn($existing);

        $path = $this->writeCsv([$postId, 'My Group', '', 'Bob', 'bob@example.com', '555-9999', '', '', '', '', '', '']);

        $result = $this->importer->import($path, dryRun: true);

        unlink($path);

        $this->assertSame(1, $result->getUpdated());
        $this->assertSame([], self::$dispatchedGroupChangingEvents, 'No event on dry runs.');
    }

    /**
     * @test
     */
    public function create_path_does_not_dispatch_group_changing(): void
    {
        // Group ID column omitted entirely — the importer takes the
        // create branch via createGroupPost + saveNewGroup. saveNewGroup
        // intentionally does not fire unity/group_changing (creates are
        // summary-only).
        $this->groupRepository->shouldNotReceive('findById');

        // No existing groups by name.
        $this->groupRepository->shouldReceive('findAll')->andReturn([])->byDefault();

        $path = $this->writeCsvNoId(['', 'Brand New Group', '', 'Charlie', 'c@example.com', '555-1111', '', '', '', '', '', '']);

        $GLOBALS['__reconcile_test_wp_insert_post_returns'] = 7300;

        $result = $this->importer->import($path, dryRun: false);

        unlink($path);

        $this->assertSame(1, $result->getCreated());
        $this->assertSame([], self::$dispatchedGroupChangingEvents, 'No event on creates.');
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * @param array<int, array{0: string, 1: string, 2: string}> $contactRows
     */
    private function makeGroup(int $id, string $title, array $contactRows): Group
    {
        $contacts = [];
        foreach ($contactRows as [$name, $email, $phone]) {
            $contacts[] = $this->makeContact($name, $email, $phone);
        }

        $group = Mockery::mock(Group::class);
        $group->shouldReceive('getId')->andReturn($id);
        $group->shouldReceive('getTitle')->andReturn($title);
        $group->shouldReceive('getContacts')->andReturn($contacts);
        $group->shouldReceive('getMeetings')->andReturn([]);
        return $group;
    }

    private function makeContact(string $name, string $email, string $phone): Contact
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
    private function writeCsv(array $row): string
    {
        return $this->writeRawCsv(
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
    private function writeCsvNoId(array $row): string
    {
        return $this->writeRawCsv(
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
    private function writeRawCsv(array $headers, array $rows): string
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
}
