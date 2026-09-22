<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use function Brain\Monkey\Functions\when;
use Mockery;
use BleedingDeacons\WpMocks\WpState;
use Reconcile\Position\PositionImporter;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionFactory;
use Unity\Positions\Interfaces\PositionRepository;

/*
 * Tests for PositionImporter.
 */

covers(PositionImporter::class);

const POSITION_IMPORT_HEADERS = [
    'Position ID', 'Position Name', 'Position Email',
    'Minimum Sobriety', 'Term Years', 'Short Description', 'Summary',
];

/**
 * @param array<int, array<int, string>> $rows
 */
function positionImportCsv(array $headers, array $rows): string
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

/** @return Position&Mockery\MockInterface */
function importedPosition(int $id = 5)
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

dataset('invalid position fields', function () {
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
});

beforeEach(function () {
    $this->repo = Mockery::mock(PositionRepository::class);
    $this->factory = Mockery::mock(PositionFactory::class);
    // The internal PositionLookup builds its cache from findAll(); default to
    // no positions so name resolution misses unless a test overrides it.
    $this->repo->shouldReceive('findAll')->andReturn([])->byDefault();

    $this->importer = function (): PositionImporter {
        return new PositionImporter($this->repo, $this->factory);
    };
});

// ─── dependency + column errors ─────────────────────────────────
describe('dependency + column errors', function () {
    it('reports a null repository as an error', function () {
        $result = (new PositionImporter(null, $this->factory))->import(positionImportCsv(POSITION_IMPORT_HEADERS, []));
        expect($result->hasErrors())->toBeTrue();
    });

    it('reports a null factory as an error', function () {
        $result = (new PositionImporter($this->repo, null))->import(positionImportCsv(POSITION_IMPORT_HEADERS, []));
        expect($result->hasErrors())->toBeTrue();
    });

    it('reports missing identifier columns as an error', function () {
        // Only Summary — neither Position ID nor Position Name present.
        $result = ($this->importer)()->import(positionImportCsv(['Summary'], [['x']]));
        expect($result->hasErrors())->toBeTrue();
    });
});

// ─── dry run ────────────────────────────────────────────────────
describe('dry run', function () {
    it('counts an existing position as an update', function () {
        $this->repo->shouldReceive('findById')->with(5)->andReturn(importedPosition(5));

        $result = ($this->importer)()->import(
            positionImportCsv(POSITION_IMPORT_HEADERS, [['5', 'Chair', 'c@example.com', '24', '3', 'Chairs', 'Runs']]),
            true
        );

        expect($result->getUpdated())->toBe(1)
            ->and($result->getCreated())->toBe(0);
    });

    it('counts an unresolved name as a create', function () {
        // findAll returns [] (default) so the name resolves to nothing → create.
        $result = ($this->importer)()->import(
            positionImportCsv(POSITION_IMPORT_HEADERS, [['', 'Brand New', 'n@example.com', '12', '2', 'New', 'Summary']]),
            true
        );

        expect($result->getCreated())->toBe(1);
    });
});

// ─── row skips ──────────────────────────────────────────────────
describe('row skips', function () {
    it('skips a row with an empty ID and name', function () {
        $result = ($this->importer)()->import(
            positionImportCsv(POSITION_IMPORT_HEADERS, [['', '', 'e@example.com', '12', '2', 'x', 'y']])
        );

        expect($result->getSkipped())->toBe(1);
    });

    it('skips a non-numeric ID', function () {
        $result = ($this->importer)()->import(
            positionImportCsv(POSITION_IMPORT_HEADERS, [['abc', 'Chair', 'e@example.com', '12', '2', 'x', 'y']])
        );

        expect($result->getSkipped())->toBe(1);
    });

    it('skips an ID that does not exist', function () {
        $this->repo->shouldReceive('findById')->with(99)->andReturn(null);

        $result = ($this->importer)()->import(
            positionImportCsv(POSITION_IMPORT_HEADERS, [['99', 'Chair', 'e@example.com', '12', '2', 'x', 'y']])
        );

        expect($result->getSkipped())->toBe(1);
    });
});

// ─── real create / update ───────────────────────────────────────
describe('real create / update', function () {
    it('updates an existing position', function () {
        $this->repo->shouldReceive('findById')->with(5)->andReturn(importedPosition(5));
        $this->factory->shouldReceive('createNew')->andReturn(importedPosition(5));
        $this->repo->shouldReceive('save')->once()->andReturn(true);

        $result = ($this->importer)()->import(
            positionImportCsv(POSITION_IMPORT_HEADERS, [['5', 'Chair', 'c@example.com', '24', '3', 'Chairs', 'Runs']])
        );

        expect($result->getUpdated())->toBe(1);
    });

    it('creates a new position from a name', function () {
        WpState::$nextPostId = 77;
        $this->factory->shouldReceive('createNew')->andReturn(importedPosition(77));
        $this->repo->shouldReceive('save')->once()->andReturn(true);

        $result = ($this->importer)()->import(
            positionImportCsv(POSITION_IMPORT_HEADERS, [['', 'Brand New', 'n@example.com', '12', '2', 'New', 'Summary']])
        );

        expect($result->getCreated())->toBe(1);
    });

    it('skips a merged position that is invalid', function () {
        $this->repo->shouldReceive('findById')->with(5)->andReturn(importedPosition(5));

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

        $result = ($this->importer)()->import(
            positionImportCsv(POSITION_IMPORT_HEADERS, [['5', 'Chair', '', '24', '3', 'Chairs', 'Runs']])
        );

        expect($result->getSkipped())->toBe(1);
    });

    it('skips an update whose save fails', function () {
        $this->repo->shouldReceive('findById')->with(5)->andReturn(importedPosition(5));
        $this->factory->shouldReceive('createNew')->andReturn(importedPosition(5));
        $this->repo->shouldReceive('save')->once()->andReturn(false);

        $result = ($this->importer)()->import(
            positionImportCsv(POSITION_IMPORT_HEADERS, [['5', 'Chair', 'c@example.com', '24', '3', 'Chairs', 'Runs']])
        );

        expect($result->getSkipped())->toBe(1)
            ->and($result->getUpdated())->toBe(0);
    });

    it('skips a create whose post insert fails', function () {
        // wp_insert_post returns 0 → the row cannot be created.
        when('wp_insert_post')->justReturn(0);
        // See PositionImporterFailureTest: createNew() is reached before the
        // insert is attempted, so it needs an expectation. Without one the
        // row was skipped because Mockery threw and the importer caught it,
        // not because wp_insert_post returned 0.
        $this->factory->shouldReceive('createNew')->andReturn(importedPosition(77))->byDefault();

        $result = ($this->importer)()->import(
            positionImportCsv(POSITION_IMPORT_HEADERS, [['', 'Brand New', 'n@example.com', '12', '2', 'New', 'Summary']])
        );

        expect($result->getSkipped())->toBe(1)
            ->and($result->getCreated())->toBe(0);
    });

    it('skips a create whose field save fails', function () {
        // Post inserts, but the field save fails afterwards.
        WpState::$nextPostId = 77;
        $this->factory->shouldReceive('createNew')->andReturn(importedPosition(77));
        $this->repo->shouldReceive('save')->once()->andReturn(false);

        $result = ($this->importer)()->import(
            positionImportCsv(POSITION_IMPORT_HEADERS, [['', 'Brand New', 'n@example.com', '12', '2', 'New', 'Summary']])
        );

        expect($result->getSkipped())->toBe(1)
            ->and($result->getCreated())->toBe(0);
    });

    it('updates the existing position a name resolves to', function () {
        // The internal lookup builds its cache from findAll(); a matching name
        // resolves to an existing id, taking the update path (not create).
        $match = importedPosition(5);
        $match->shouldReceive('getLongName')->andReturn('Chair');
        $this->repo->shouldReceive('findAll')->andReturn([$match]);
        $this->repo->shouldReceive('findById')->with(5)->andReturn(importedPosition(5));
        $this->factory->shouldReceive('createNew')->andReturn(importedPosition(5));
        $this->repo->shouldReceive('save')->once()->andReturn(true);

        $result = ($this->importer)()->import(
            positionImportCsv(POSITION_IMPORT_HEADERS, [['', 'Chair', 'c@example.com', '24', '3', 'Chairs', 'Runs']])
        );

        expect($result->getUpdated())->toBe(1)
            ->and($result->getCreated())->toBe(0);
    });

    it('reports the row as skipped for each invalid field', function (array $getters) {
        $this->repo->shouldReceive('findById')->with(5)->andReturn(importedPosition(5));

        $merged = Mockery::mock(Position::class);
        foreach ($getters as $method => $value) {
            $merged->shouldReceive($method)->andReturn($value);
        }
        $this->factory->shouldReceive('createNew')->andReturn($merged);
        $this->repo->shouldReceive('save')->never();

        $result = ($this->importer)()->import(
            positionImportCsv(POSITION_IMPORT_HEADERS, [['5', 'Chair', 'c@example.com', '24', '3', 'Chairs', 'Runs']])
        );

        expect($result->getSkipped())->toBe(1);
    })->with('invalid position fields');

    it('processes multiple rows in one file', function () {
        // Row 1 updates (id 5), row 2 is skipped (empty), row 3 creates.
        $this->repo->shouldReceive('findById')->with(5)->andReturn(importedPosition(5));
        $this->factory->shouldReceive('createNew')->andReturn(importedPosition(5));
        $this->repo->shouldReceive('save')->andReturn(true);
        WpState::$nextPostId = 90;

        $result = ($this->importer)()->import(positionImportCsv(POSITION_IMPORT_HEADERS, [
            ['5', 'Chair', 'c@example.com', '24', '3', 'Chairs', 'Runs'],
            ['', '', 'x@example.com', '12', '2', 'x', 'y'],
            ['', 'Fresh', 'f@example.com', '12', '2', 'Fresh', 'Summary'],
        ]));

        expect($result->getTotalRows())->toBe(3)
            ->and($result->getUpdated())->toBe(1)
            ->and($result->getCreated())->toBe(1)
            ->and($result->getSkipped())->toBe(1);
    });
});
