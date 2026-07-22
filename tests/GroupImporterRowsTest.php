<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Reconcile\Group\GroupImporter;
use Unity\Contacts\Interfaces\ContactFactory;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupFactory;
use Unity\Groups\Interfaces\GroupRepository;

/**
 * Row-level and dependency tests for GroupImporter (the changing-event
 * behaviour is covered separately in GroupImporterChangingEventTest).
 *
 * @covers \Reconcile\Group\GroupImporter
 */
class GroupImporterRowsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var GroupRepository&Mockery\MockInterface */
    private $repo;
    /** @var GroupFactory&Mockery\MockInterface */
    private $factory;
    /** @var ContactFactory&Mockery\MockInterface */
    private $contactFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = Mockery::mock(GroupRepository::class);
        $this->factory = Mockery::mock(GroupFactory::class);
        $this->contactFactory = Mockery::mock(ContactFactory::class);
        $this->repo->shouldReceive('findAll')->andReturn([])->byDefault();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private const HEADERS = [
        'Group ID', 'Group Name', 'Group Email',
        'Contact 1 Name', 'Contact 1 Email', 'Contact 1 Phone',
    ];

    private function importer(): GroupImporter
    {
        return new GroupImporter($this->repo, $this->factory, $this->contactFactory);
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function writeCsv(array $headers, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'grp_import_') . '.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);
        return $path;
    }

    /** @return Group&Mockery\MockInterface */
    private function group(int $id = 5)
    {
        $g = Mockery::mock(Group::class);
        $g->shouldReceive('getId')->andReturn($id);
        $g->shouldReceive('getTitle')->andReturn('Tuesday Group');
        $g->shouldReceive('isValid')->andReturn(true);
        return $g;
    }

    /**
     * @test
     */
    public function null_repository_is_an_error(): void
    {
        $result = (new GroupImporter(null, $this->factory, $this->contactFactory))
            ->import($this->writeCsv(self::HEADERS, []));
        $this->assertTrue($result->hasErrors());
    }

    /**
     * @test
     */
    public function null_factory_is_an_error(): void
    {
        $result = (new GroupImporter($this->repo, null, $this->contactFactory))
            ->import($this->writeCsv(self::HEADERS, []));
        $this->assertTrue($result->hasErrors());
    }

    /**
     * @test
     */
    public function missing_email_column_is_an_error(): void
    {
        // Group ID present but no Group Email column.
        $result = $this->importer()->import($this->writeCsv(['Group ID'], [['5']]));
        $this->assertTrue($result->hasErrors());
    }

    /**
     * @test
     */
    public function missing_identifier_columns_is_an_error(): void
    {
        // Email present, but neither Group ID nor Group Name.
        $result = $this->importer()->import($this->writeCsv(['Group Email'], [['a@b.com']]));
        $this->assertTrue($result->hasErrors());
    }

    /**
     * @test
     */
    public function empty_id_and_name_row_is_skipped(): void
    {
        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['', '', 'e@example.com', '', '', '']])
        );
        $this->assertSame(1, $result->getSkipped());
    }

    /**
     * @test
     */
    public function non_numeric_id_is_skipped(): void
    {
        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['abc', 'Tuesday', 'e@example.com', '', '', '']])
        );
        $this->assertSame(1, $result->getSkipped());
    }

    /**
     * @test
     */
    public function id_that_does_not_exist_is_skipped(): void
    {
        $this->repo->shouldReceive('findById')->with(99)->andReturn(null);

        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['99', 'Tuesday', 'e@example.com', '', '', '']])
        );
        $this->assertSame(1, $result->getSkipped());
    }

    /**
     * @test
     */
    public function a_name_resolving_to_an_unloadable_group_is_skipped(): void
    {
        // The lookup resolves "Tuesday" to id 5, but findById(5) returns null,
        // so the row is skipped rather than silently created.
        $match = Mockery::mock(Group::class);
        $match->shouldReceive('getId')->andReturn(5);
        $match->shouldReceive('getTitle')->andReturn('Tuesday');
        $this->repo->shouldReceive('findAll')->andReturn([$match]);
        $this->repo->shouldReceive('findById')->with(5)->andReturn(null);

        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['', 'Tuesday', 'e@example.com', '', '', '']])
        );

        $this->assertSame(1, $result->getSkipped());
    }

    /**
     * @test
     */
    public function dry_run_counts_an_existing_group_as_an_update(): void
    {
        $this->repo->shouldReceive('findById')->with(5)->andReturn($this->group(5));

        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['5', 'Tuesday', 'e@example.com', '', '', '']]),
            true
        );

        $this->assertSame(1, $result->getUpdated());
    }

    /**
     * @test
     */
    public function dry_run_counts_an_unresolved_name_as_a_create(): void
    {
        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['', 'Brand New', 'e@example.com', '', '', '']]),
            true
        );

        $this->assertSame(1, $result->getCreated());
    }

    /**
     * @test
     */
    public function creates_a_new_group_with_contacts(): void
    {
        // A named group that does not resolve is created via wp_insert_post,
        // exercising contact building and the meta-field writes.
        $GLOBALS['__reconcile_test_wp_insert_post_returns'] = 88;

        $this->contactFactory->shouldReceive('create')
            ->andReturnUsing(function ($name, $email, $phone) {
                $c = Mockery::mock(\Unity\Contacts\Interfaces\Contact::class);
                $c->shouldReceive('getName')->andReturn($name);
                $c->shouldReceive('getEmail')->andReturn($email);
                $c->shouldReceive('getPhone')->andReturn($phone);
                return $c;
            });

        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [
                ['', 'Brand New', 'e@example.com', 'Alice', 'alice@example.com', '0700'],
            ])
        );

        $this->assertSame(1, $result->getCreated());
        $this->assertSame(0, $result->getSkipped());

        unset($GLOBALS['__reconcile_test_wp_insert_post_returns']);
    }

    /**
     * @test
     */
    public function a_create_whose_post_insert_fails_is_skipped(): void
    {
        $GLOBALS['__reconcile_test_wp_insert_post_returns'] = 0;
        $this->contactFactory->shouldReceive('create')->andReturnUsing(
            fn () => Mockery::mock(\Unity\Contacts\Interfaces\Contact::class)->shouldIgnoreMissing()
        );

        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['', 'Brand New', 'e@example.com', '', '', '']])
        );

        $this->assertSame(1, $result->getSkipped());
        $this->assertSame(0, $result->getCreated());

        unset($GLOBALS['__reconcile_test_wp_insert_post_returns']);
    }

    /**
     * @test
     */
    public function processes_multiple_rows_in_one_file(): void
    {
        $existing = Mockery::mock(Group::class)->shouldIgnoreMissing();
        $existing->shouldReceive('getId')->andReturn(5);
        $existing->shouldReceive('isValid')->andReturn(true);
        $this->repo->shouldReceive('findById')->with(5)->andReturn($existing);
        $GLOBALS['__reconcile_test_wp_insert_post_returns'] = 90;
        $this->contactFactory->shouldReceive('create')->andReturnUsing(
            fn () => Mockery::mock(\Unity\Contacts\Interfaces\Contact::class)->shouldIgnoreMissing()
        );

        $result = $this->importer()->import($this->writeCsv(self::HEADERS, [
            ['5', 'Tuesday', 'e@example.com', '', '', ''],   // update
            ['', '', 'x@example.com', '', '', ''],           // skip (no id/name)
            ['', 'Fresh Group', 'f@example.com', '', '', ''], // create
        ]));

        $this->assertSame(3, $result->getTotalRows());
        $this->assertSame(1, $result->getUpdated());
        $this->assertSame(1, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());

        unset($GLOBALS['__reconcile_test_wp_insert_post_returns']);
    }

    /**
     * @test
     */
    public function updates_an_existing_group(): void
    {
        $existing = Mockery::mock(Group::class)->shouldIgnoreMissing();
        $existing->shouldReceive('getId')->andReturn(5);
        $existing->shouldReceive('isValid')->andReturn(true);
        $this->repo->shouldReceive('findById')->with(5)->andReturn($existing);

        $this->contactFactory->shouldReceive('create')->andReturnUsing(function ($name, $email, $phone) {
            return Mockery::mock(\Unity\Contacts\Interfaces\Contact::class)->shouldIgnoreMissing();
        });

        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [
                ['5', 'Tuesday', 'e@example.com', 'Alice', 'alice@example.com', '0700'],
            ])
        );

        $this->assertSame(1, $result->getUpdated());
    }
}
