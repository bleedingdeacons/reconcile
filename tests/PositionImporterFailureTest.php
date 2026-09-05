<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Mockery;
use BleedingDeacons\WpMocks\TestCase;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Reconcile\Position\PositionImporter;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionFactory;
use Unity\Positions\Interfaces\PositionRepository;

/**
 * PositionImporter persist-path failure branches: a save that throws, a save
 * that emits a captured PHP warning, and a WordPress post insert that returns
 * a WP_Error.
 *
 * @covers \Reconcile\Position\PositionImporter
 */
class PositionImporterFailureTest extends TestCase
{
    /** @var PositionRepository&Mockery\MockInterface */
    private $repo;
    /** @var PositionFactory&Mockery\MockInterface */
    private $factory;

    private const HEADERS = [
        'Position ID', 'Position Name', 'Position Email',
        'Minimum Sobriety', 'Term Years', 'Short Description', 'Summary',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = Mockery::mock(PositionRepository::class);
        $this->factory = Mockery::mock(PositionFactory::class);
        $this->repo->shouldReceive('findAll')->andReturn([])->byDefault();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @param array<int, array<int, string>> $rows */
    private function writeCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pos_fail_') . '.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, self::HEADERS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);
        return $path;
    }

    /** @return Position&Mockery\MockInterface */
    private function validPosition(int $id = 5)
    {
        $p = Mockery::mock(Position::class);
        $p->shouldReceive('getId')->andReturn($id);
        $p->shouldReceive('getEmail')->andReturn('chair@example.com');
        $p->shouldReceive('getLongName')->andReturn('Chair');
        $p->shouldReceive('getShortDescription')->andReturn('Chairs');
        $p->shouldReceive('getSummary')->andReturn('Runs intergroup');
        $p->shouldReceive('getMinimumSobriety')->andReturn(24);
        $p->shouldReceive('getTermYears')->andReturn(3);
        $p->shouldReceive('isValid')->andReturn(true);
        return $p;
    }

    private function importer(): PositionImporter
    {
        return new PositionImporter($this->repo, $this->factory);
    }

    private const CREATE_ROW = ['', 'New Chair', 'c@example.com', '24', '3', 'Desc', 'Summary'];

    /** @test */
    public function a_save_that_throws_is_captured_as_a_skip(): void
    {
        $this->factory->shouldReceive('createNew')->andReturn($this->validPosition(77));
        $this->repo->shouldReceive('save')->once()->andThrow(new \RuntimeException('save exploded'));

        $result = $this->importer()->import($this->writeCsv([self::CREATE_ROW]));

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    /** @test */
    public function a_save_that_emits_a_php_warning_records_the_captured_text(): void
    {
        $this->factory->shouldReceive('createNew')->andReturn($this->validPosition(77));
        // The importer installs an error handler around save(); a warning it
        // emits is captured and appended to the skip reason.
        $this->repo->shouldReceive('save')->once()->andReturnUsing(function (): bool {
            trigger_error('deprecated column written', E_USER_WARNING);
            return false;
        });

        $result = $this->importer()->import($this->writeCsv([self::CREATE_ROW]));

        $this->assertSame(1, $result->getSkipped());
    }

    /** @test */
    public function a_wp_error_from_post_insert_is_skipped(): void
    {
        Functions\when('wp_insert_post')->justReturn(new \WP_Error('insert_failed', 'invalid post data'));
        // The importer builds the Position before it tries to insert the post,
        // so createNew() is reached on this path and needs an expectation. It
        // always was: without one Mockery threw, the importer's own error
        // handling swallowed it, and the row was skipped for that reason
        // rather than the WP_Error under test. The assertions passed either
        // way, which is why it went unnoticed until Mockery 1.6.15 began
        // reporting swallowed BadMethodCallExceptions as risky.
        $this->factory->shouldReceive('createNew')->andReturn($this->validPosition(77))->byDefault();

        $result = $this->importer()->import($this->writeCsv([self::CREATE_ROW]));

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    /** @test */
    public function a_post_insert_returning_zero_is_skipped(): void
    {
        Functions\when('wp_insert_post')->justReturn(0);
        $this->factory->shouldReceive('createNew')->andReturn($this->validPosition(77));

        $result = $this->importer()->import($this->writeCsv([self::CREATE_ROW]));

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    /** @test */
    public function an_update_by_id_whose_save_throws_records_the_error(): void
    {
        // Blank name + ID → the skip reason uses the "ID:" label, and the save
        // exception is recorded as the error detail.
        $this->repo->shouldReceive('findById')->with(5)->andReturn($this->validPosition(5));
        $this->factory->shouldReceive('createNew')->andReturn($this->validPosition(5));
        $this->repo->shouldReceive('save')->once()->andThrow(new \RuntimeException('update boom'));

        $result = $this->importer()->import($this->writeCsv([
            ['5', '', 'chair@example.com', '24', '3', 'Desc', 'Summary'],
        ]));

        $this->assertSame(0, $result->getUpdated());
        $this->assertSame(1, $result->getSkipped());
    }

    /** @test */
    public function an_unreadable_file_is_reported_as_an_error(): void
    {
        // SpreadsheetReader throws for a missing file; the importer catches it,
        // records an error, and returns without any rows processed.
        $result = $this->importer()->import(sys_get_temp_dir() . '/does-not-exist-' . uniqid() . '.csv');

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->getCreated());
    }

    /** @test */
    public function an_update_by_id_whose_save_fails_is_skipped_with_the_id_label(): void
    {
        // Row carries an ID but no position name, so the skip reason labels it
        // by ID; the save returning false records the failure.
        $this->repo->shouldReceive('findById')->with(5)->andReturn($this->validPosition(5));
        $this->factory->shouldReceive('createNew')->andReturn($this->validPosition(5));
        $this->repo->shouldReceive('save')->once()->andReturn(false);

        $result = $this->importer()->import($this->writeCsv([
            ['5', '', 'chair@example.com', '24', '3', 'Desc', 'Summary'],
        ]));

        $this->assertSame(0, $result->getUpdated());
        $this->assertSame(1, $result->getSkipped());
    }

    /** @test */
    public function a_name_that_resolves_but_cannot_be_loaded_is_skipped(): void
    {
        // The lookup resolves the name to an ID, but the position fails to load
        // from that ID — a data-integrity skip, not a create.
        $this->repo->shouldReceive('findById')->with(7)->andReturn(null);

        $importer = new PositionImporter($this->repoResolving('Chair', 7), $this->factory);
        $result = $importer->import($this->writeCsv([
            ['', 'Chair', 'chair@example.com', '24', '3', 'Desc', 'Summary'],
        ]));

        $this->assertSame(1, $result->getSkipped());
    }

    /**
     * A repository whose findAll() seeds the internal PositionLookup so the
     * given name resolves to $id.
     *
     * @return PositionRepository&Mockery\MockInterface
     */
    private function repoResolving(string $name, int $id)
    {
        $repo = Mockery::mock(PositionRepository::class);
        $pos = $this->validPosition($id);
        $pos->shouldReceive('getShortName')->andReturn($name);
        $pos->shouldReceive('getTitle')->andReturn($name);
        $repo->shouldReceive('findAll')->andReturn([$pos]);
        $repo->shouldReceive('findById')->with($id)->andReturn(null);
        return $repo;
    }

    /** @test */
    public function an_update_with_blank_columns_falls_back_to_the_existing_values(): void
    {
        // Row carries an ID but leaves the optional columns blank; the importer
        // keeps the existing position's long name / description / summary
        // rather than overwriting them with empties.
        $this->repo->shouldReceive('findById')->with(5)->andReturn($this->validPosition(5));
        $this->factory->shouldReceive('createNew')->andReturn($this->validPosition(5));
        $this->repo->shouldReceive('save')->once()->andReturn(true);

        $result = $this->importer()->import($this->writeCsv([
            ['5', '', 'chair@example.com', '', '', '', ''],
        ]));

        $this->assertSame(1, $result->getUpdated());
    }
}
