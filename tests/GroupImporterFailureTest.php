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
 * GroupImporter failure branches: an unreadable file, and the WordPress
 * post insert/update returning a WP_Error. GroupImporter persists through
 * wp_insert_post/wp_update_post directly (rather than a repository), so the
 * error stubs are driven via the bootstrap globals.
 *
 * @covers \Reconcile\Group\GroupImporter
 */
class GroupImporterFailureTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var GroupRepository&Mockery\MockInterface */
    private $repo;
    /** @var GroupFactory&Mockery\MockInterface */
    private $factory;
    /** @var ContactFactory&Mockery\MockInterface */
    private $contactFactory;

    private const HEADERS = [
        'Group ID', 'Group Name', 'Group Email',
        'Contact 1 Name', 'Contact 1 Email', 'Contact 1 Phone',
    ];

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
        unset(
            $GLOBALS['__reconcile_test_wp_insert_post_error'],
            $GLOBALS['__reconcile_test_wp_update_post_error'],
            $GLOBALS['__reconcile_test_wp_insert_post_returns'],
            $GLOBALS['__reconcile_test_update_meta_throws'],
            $GLOBALS['__reconcile_test_update_meta_warns'],
        );
        parent::tearDown();
    }

    /** @param array<int, array<int, string>> $rows */
    private function writeCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'grp_fail_') . '.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, self::HEADERS, ',', '"', '');
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

    private function importer(): GroupImporter
    {
        return new GroupImporter($this->repo, $this->factory, $this->contactFactory);
    }

    private const CREATE_ROW = ['', 'New Group', 'g@example.com', 'Sam', 's@example.com', '555'];

    /** @test */
    public function an_unreadable_file_is_reported_as_an_error(): void
    {
        $result = $this->importer()->import(sys_get_temp_dir() . '/missing-' . uniqid() . '.csv');

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->getCreated());
    }

    /** @test */
    public function a_wp_error_from_post_insert_is_skipped(): void
    {
        $GLOBALS['__reconcile_test_wp_insert_post_error'] = 'insert refused';
        $this->factory->shouldReceive('createNew')->andReturn($this->group(0))->byDefault();

        $result = $this->importer()->import($this->writeCsv([self::CREATE_ROW]));

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    /** @test */
    public function a_post_insert_returning_zero_is_skipped(): void
    {
        $GLOBALS['__reconcile_test_wp_insert_post_returns'] = 0;
        $this->factory->shouldReceive('createNew')->andReturn($this->group(0))->byDefault();

        $result = $this->importer()->import($this->writeCsv([self::CREATE_ROW]));

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    /** @test */
    public function an_id_lookup_swallows_a_repository_exception_and_skips(): void
    {
        // findById() throwing is caught and returns null, so the ID row is
        // treated as "group not found" and skipped.
        $this->repo->shouldReceive('findById')->with(9)->andThrow(new \RuntimeException('db down'));

        $result = $this->importer()->import($this->writeCsv([
            ['9', 'Ghost Group', 'g@example.com', 'Sam', 's@example.com', '555'],
        ]));

        $this->assertSame(1, $result->getSkipped());
    }

    /** @test */
    public function a_wp_error_from_post_update_is_skipped(): void
    {
        $GLOBALS['__reconcile_test_wp_update_post_error'] = 'update refused';
        $this->repo->shouldReceive('findById')->with(5)->andReturn($this->group(5));
        $this->factory->shouldReceive('createNew')->andReturn($this->group(5))->byDefault();

        $result = $this->importer()->import($this->writeCsv([
            ['5', 'Existing Group', 'g@example.com', 'Sam', 's@example.com', '555'],
        ]));

        $this->assertSame(0, $result->getUpdated());
        $this->assertSame(1, $result->getSkipped());
    }

    /** @test */
    public function a_meta_write_that_throws_on_create_is_skipped(): void
    {
        $GLOBALS['__reconcile_test_update_meta_throws'] = true;
        $this->factory->shouldReceive('createNew')->andReturn($this->group(0))->byDefault();

        $result = $this->importer()->import($this->writeCsv([self::CREATE_ROW]));

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    /** @test */
    public function a_meta_write_that_throws_on_update_is_skipped(): void
    {
        $GLOBALS['__reconcile_test_update_meta_throws'] = true;
        $this->repo->shouldReceive('findById')->with(5)->andReturn($this->group(5));
        $this->factory->shouldReceive('createNew')->andReturn($this->group(5))->byDefault();

        $result = $this->importer()->import($this->writeCsv([
            ['5', 'Existing Group', 'g@example.com', 'Sam', 's@example.com', '555'],
        ]));

        $this->assertSame(0, $result->getUpdated());
        $this->assertSame(1, $result->getSkipped());
    }

    /** @test */
    public function a_meta_write_warning_is_captured_on_create(): void
    {
        // A PHP warning during the meta write is captured by the save wrapper's
        // error handler; the save still succeeds.
        $GLOBALS['__reconcile_test_update_meta_warns'] = true;
        $this->factory->shouldReceive('createNew')->andReturn($this->group(1))->byDefault();

        $result = $this->importer()->import($this->writeCsv([self::CREATE_ROW]));

        $this->assertSame(1, $result->getCreated());
    }

    /** @test */
    public function a_meta_write_warning_is_captured_on_update(): void
    {
        $GLOBALS['__reconcile_test_update_meta_warns'] = true;
        $this->repo->shouldReceive('findById')->with(5)->andReturn($this->group(5));
        $this->factory->shouldReceive('createNew')->andReturn($this->group(5))->byDefault();

        $result = $this->importer()->import($this->writeCsv([
            ['5', 'Existing Group', 'g@example.com', 'Sam', 's@example.com', '555'],
        ]));

        $this->assertSame(1, $result->getUpdated());
    }
}
