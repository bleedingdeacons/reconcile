<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Reconcile\Position\PositionImporter;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionFactory;
use Unity\Positions\Interfaces\PositionRepository;

/**
 * Tests for PositionImporter.
 *
 * @covers \Reconcile\Position\PositionImporter
 */
class PositionImporterTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var PositionRepository&Mockery\MockInterface */
    private $repo;
    /** @var PositionFactory&Mockery\MockInterface */
    private $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = Mockery::mock(PositionRepository::class);
        $this->factory = Mockery::mock(PositionFactory::class);
        // The internal PositionLookup builds its cache from findAll(); default to
        // no positions so name resolution misses unless a test overrides it.
        $this->repo->shouldReceive('findAll')->andReturn([])->byDefault();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function writeCsv(array $headers, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pos_import_') . '.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);
        return $path;
    }

    private function importer(): PositionImporter
    {
        return new PositionImporter($this->repo, $this->factory);
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

    private const HEADERS = [
        'Position ID', 'Position Name', 'Position Email',
        'Minimum Sobriety', 'Term Years', 'Short Description', 'Summary',
    ];

    // ─── dependency + column errors ─────────────────────────────────

    /**
     * @test
     */
    public function null_repository_is_an_error(): void
    {
        $result = (new PositionImporter(null, $this->factory))->import($this->writeCsv(self::HEADERS, []));
        $this->assertTrue($result->hasErrors());
    }

    /**
     * @test
     */
    public function null_factory_is_an_error(): void
    {
        $result = (new PositionImporter($this->repo, null))->import($this->writeCsv(self::HEADERS, []));
        $this->assertTrue($result->hasErrors());
    }

    /**
     * @test
     */
    public function missing_identifier_columns_is_an_error(): void
    {
        // Only Summary — neither Position ID nor Position Name present.
        $result = $this->importer()->import($this->writeCsv(['Summary'], [['x']]));
        $this->assertTrue($result->hasErrors());
    }

    // ─── dry run ────────────────────────────────────────────────────

    /**
     * @test
     */
    public function dry_run_counts_an_existing_position_as_an_update(): void
    {
        $this->repo->shouldReceive('findById')->with(5)->andReturn($this->validPosition(5));

        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['5', 'Chair', 'c@example.com', '24', '3', 'Chairs', 'Runs']]),
            true
        );

        $this->assertSame(1, $result->getUpdated());
        $this->assertSame(0, $result->getCreated());
    }

    /**
     * @test
     */
    public function dry_run_counts_an_unresolved_name_as_a_create(): void
    {
        // findAll returns [] (default) so the name resolves to nothing → create.
        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['', 'Brand New', 'n@example.com', '12', '2', 'New', 'Summary']]),
            true
        );

        $this->assertSame(1, $result->getCreated());
    }

    // ─── row skips ──────────────────────────────────────────────────

    /**
     * @test
     */
    public function empty_id_and_name_row_is_skipped(): void
    {
        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['', '', 'e@example.com', '12', '2', 'x', 'y']])
        );

        $this->assertSame(1, $result->getSkipped());
    }

    /**
     * @test
     */
    public function non_numeric_id_is_skipped(): void
    {
        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['abc', 'Chair', 'e@example.com', '12', '2', 'x', 'y']])
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
            $this->writeCsv(self::HEADERS, [['99', 'Chair', 'e@example.com', '12', '2', 'x', 'y']])
        );

        $this->assertSame(1, $result->getSkipped());
    }

    // ─── real create / update ───────────────────────────────────────

    /**
     * @test
     */
    public function updates_an_existing_position(): void
    {
        $this->repo->shouldReceive('findById')->with(5)->andReturn($this->validPosition(5));
        $this->factory->shouldReceive('createNew')->andReturn($this->validPosition(5));
        $this->repo->shouldReceive('save')->once()->andReturn(true);

        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['5', 'Chair', 'c@example.com', '24', '3', 'Chairs', 'Runs']])
        );

        $this->assertSame(1, $result->getUpdated());
    }

    /**
     * @test
     */
    public function creates_a_new_position_from_a_name(): void
    {
        $GLOBALS['__reconcile_test_wp_insert_post_returns'] = 77;
        $this->factory->shouldReceive('createNew')->andReturn($this->validPosition(77));
        $this->repo->shouldReceive('save')->once()->andReturn(true);

        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['', 'Brand New', 'n@example.com', '12', '2', 'New', 'Summary']])
        );

        $this->assertSame(1, $result->getCreated());

        unset($GLOBALS['__reconcile_test_wp_insert_post_returns']);
    }

    /**
     * @test
     */
    public function a_merged_position_that_is_invalid_is_skipped(): void
    {
        $this->repo->shouldReceive('findById')->with(5)->andReturn($this->validPosition(5));

        // The factory yields a position missing its email, so the importer
        // reports the specific invalid field rather than attempting a save.
        $invalid = Mockery::mock(Position::class);
        $invalid->shouldReceive('getId')->andReturn(5);
        $invalid->shouldReceive('getEmail')->andReturn('');
        $invalid->shouldReceive('getLongName')->andReturn('Chair');
        $invalid->shouldReceive('getShortDescription')->andReturn('Chairs');
        $invalid->shouldReceive('getSummary')->andReturn('Runs');
        $invalid->shouldReceive('getMinimumSobriety')->andReturn(24);
        $invalid->shouldReceive('getTermYears')->andReturn(3);
        $this->factory->shouldReceive('createNew')->andReturn($invalid);
        // save must never be reached.
        $this->repo->shouldReceive('save')->never();

        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['5', 'Chair', '', '24', '3', 'Chairs', 'Runs']])
        );

        $this->assertSame(1, $result->getSkipped());
    }

    /**
     * @test
     */
    public function an_update_whose_save_fails_is_skipped(): void
    {
        $this->repo->shouldReceive('findById')->with(5)->andReturn($this->validPosition(5));
        $this->factory->shouldReceive('createNew')->andReturn($this->validPosition(5));
        $this->repo->shouldReceive('save')->once()->andReturn(false);

        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['5', 'Chair', 'c@example.com', '24', '3', 'Chairs', 'Runs']])
        );

        $this->assertSame(1, $result->getSkipped());
        $this->assertSame(0, $result->getUpdated());
    }

    /**
     * @test
     */
    public function a_create_whose_post_insert_fails_is_skipped(): void
    {
        // wp_insert_post returns 0 → the row cannot be created.
        $GLOBALS['__reconcile_test_wp_insert_post_returns'] = 0;

        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['', 'Brand New', 'n@example.com', '12', '2', 'New', 'Summary']])
        );

        $this->assertSame(1, $result->getSkipped());
        $this->assertSame(0, $result->getCreated());

        unset($GLOBALS['__reconcile_test_wp_insert_post_returns']);
    }

    /**
     * @test
     */
    public function a_create_whose_field_save_fails_is_skipped(): void
    {
        // Post inserts, but the field save fails afterwards.
        $GLOBALS['__reconcile_test_wp_insert_post_returns'] = 77;
        $this->factory->shouldReceive('createNew')->andReturn($this->validPosition(77));
        $this->repo->shouldReceive('save')->once()->andReturn(false);

        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['', 'Brand New', 'n@example.com', '12', '2', 'New', 'Summary']])
        );

        $this->assertSame(1, $result->getSkipped());
        $this->assertSame(0, $result->getCreated());

        unset($GLOBALS['__reconcile_test_wp_insert_post_returns']);
    }

    /**
     * @test
     */
    public function a_name_that_resolves_to_an_existing_position_updates_it(): void
    {
        // The internal lookup builds its cache from findAll(); a matching name
        // resolves to an existing id, taking the update path (not create).
        $match = $this->validPosition(5);
        $match->shouldReceive('getLongName')->andReturn('Chair');
        $this->repo->shouldReceive('findAll')->andReturn([$match]);
        $this->repo->shouldReceive('findById')->with(5)->andReturn($this->validPosition(5));
        $this->factory->shouldReceive('createNew')->andReturn($this->validPosition(5));
        $this->repo->shouldReceive('save')->once()->andReturn(true);

        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['', 'Chair', 'c@example.com', '24', '3', 'Chairs', 'Runs']])
        );

        $this->assertSame(1, $result->getUpdated());
        $this->assertSame(0, $result->getCreated());
    }

    /**
     * @test
     * @dataProvider invalidFieldProvider
     */
    public function each_invalid_field_reports_the_row_as_skipped(array $getters): void
    {
        $this->repo->shouldReceive('findById')->with(5)->andReturn($this->validPosition(5));

        $merged = Mockery::mock(Position::class);
        foreach ($getters as $method => $value) {
            $merged->shouldReceive($method)->andReturn($value);
        }
        $this->factory->shouldReceive('createNew')->andReturn($merged);
        $this->repo->shouldReceive('save')->never();

        $result = $this->importer()->import(
            $this->writeCsv(self::HEADERS, [['5', 'Chair', 'c@example.com', '24', '3', 'Chairs', 'Runs']])
        );

        $this->assertSame(1, $result->getSkipped());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidFieldProvider(): array
    {
        $base = [
            'getId' => 5, 'getEmail' => 'c@example.com', 'getLongName' => 'Chair',
            'getShortDescription' => 'Chairs', 'getSummary' => 'Runs',
            'getMinimumSobriety' => 24, 'getTermYears' => 3,
        ];

        return [
            'no email'        => [array_merge($base, ['getEmail' => ''])],
            'no long name'    => [array_merge($base, ['getLongName' => ''])],
            'no short desc'   => [array_merge($base, ['getShortDescription' => ''])],
            'no summary'      => [array_merge($base, ['getSummary' => ''])],
            'sobriety low'    => [array_merge($base, ['getMinimumSobriety' => 3])],
            'term too short'  => [array_merge($base, ['getTermYears' => 0])],
        ];
    }

    /**
     * @test
     */
    public function processes_multiple_rows_in_one_file(): void
    {
        // Row 1 updates (id 5), row 2 is skipped (empty), row 3 creates.
        $this->repo->shouldReceive('findById')->with(5)->andReturn($this->validPosition(5));
        $this->factory->shouldReceive('createNew')->andReturn($this->validPosition(5));
        $this->repo->shouldReceive('save')->andReturn(true);
        $GLOBALS['__reconcile_test_wp_insert_post_returns'] = 90;

        $result = $this->importer()->import($this->writeCsv(self::HEADERS, [
            ['5', 'Chair', 'c@example.com', '24', '3', 'Chairs', 'Runs'],
            ['', '', 'x@example.com', '12', '2', 'x', 'y'],
            ['', 'Fresh', 'f@example.com', '12', '2', 'Fresh', 'Summary'],
        ]));

        $this->assertSame(3, $result->getTotalRows());
        $this->assertSame(1, $result->getUpdated());
        $this->assertSame(1, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());

        unset($GLOBALS['__reconcile_test_wp_insert_post_returns']);
    }
}
