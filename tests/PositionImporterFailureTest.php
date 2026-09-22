<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use function Brain\Monkey\Functions\when;
use Mockery;
use Reconcile\Position\PositionImporter;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionFactory;
use Unity\Positions\Interfaces\PositionRepository;

/*
 * PositionImporter persist-path failure branches: a save that throws, a save
 * that emits a captured PHP warning, and a WordPress post insert that returns
 * a WP_Error.
 */

covers(PositionImporter::class);

const POSITION_FAILURE_HEADERS = [
    'Position ID', 'Position Name', 'Position Email',
    'Minimum Sobriety', 'Term Years', 'Short Description', 'Summary',
];

const POSITION_FAILURE_CREATE_ROW = ['', 'New Chair', 'c@example.com', '24', '3', 'Desc', 'Summary'];

/** @param array<int, array<int, string>> $rows */
function positionFailureCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'pos_fail_') . '.csv';
    $handle = fopen($path, 'w');
    fputcsv($handle, POSITION_FAILURE_HEADERS, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }
    fclose($handle);
    return $path;
}

/** @return Position&Mockery\MockInterface */
function failingPosition(int $id = 5)
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

/**
 * A repository whose findAll() seeds the internal PositionLookup so the
 * given name resolves to $id.
 *
 * @return PositionRepository&Mockery\MockInterface
 */
function positionRepoResolving(string $name, int $id)
{
    $repo = Mockery::mock(PositionRepository::class);
    $pos = failingPosition($id);
    $pos->shouldReceive('getShortName')->andReturn($name);
    $pos->shouldReceive('getTitle')->andReturn($name);
    $repo->shouldReceive('findAll')->andReturn([$pos]);
    $repo->shouldReceive('findById')->with($id)->andReturn(null);
    return $repo;
}

beforeEach(function () {
    $this->repo = Mockery::mock(PositionRepository::class);
    $this->factory = Mockery::mock(PositionFactory::class);
    $this->repo->shouldReceive('findAll')->andReturn([])->byDefault();

    $this->importer = function (): PositionImporter {
        return new PositionImporter($this->repo, $this->factory);
    };
});

it('captures a save that throws as a skip', function () {
    $this->factory->shouldReceive('createNew')->andReturn(failingPosition(77));
    $this->repo->shouldReceive('save')->once()->andThrow(new \RuntimeException('save exploded'));

    $result = ($this->importer)()->import(positionFailureCsv([POSITION_FAILURE_CREATE_ROW]));

    expect($result->getCreated())->toBe(0)
        ->and($result->getSkipped())->toBe(1);
});

it('records the captured text of a PHP warning emitted by a save', function () {
    $this->factory->shouldReceive('createNew')->andReturn(failingPosition(77));
    // The importer installs an error handler around save(); a warning it
    // emits is captured and appended to the skip reason.
    $this->repo->shouldReceive('save')->once()->andReturnUsing(function (): bool {
        trigger_error('deprecated column written', E_USER_WARNING);
        return false;
    });

    $result = ($this->importer)()->import(positionFailureCsv([POSITION_FAILURE_CREATE_ROW]));

    expect($result->getSkipped())->toBe(1);
});

it('skips a row whose post insert returns a WP_Error', function () {
    when('wp_insert_post')->justReturn(new \WP_Error('insert_failed', 'invalid post data'));
    // The importer builds the Position before it tries to insert the post,
    // so createNew() is reached on this path and needs an expectation. It
    // always was: without one Mockery threw, the importer's own error
    // handling swallowed it, and the row was skipped for that reason
    // rather than the WP_Error under test. The assertions passed either
    // way, which is why it went unnoticed until Mockery 1.6.15 began
    // reporting swallowed BadMethodCallExceptions as risky.
    $this->factory->shouldReceive('createNew')->andReturn(failingPosition(77))->byDefault();

    $result = ($this->importer)()->import(positionFailureCsv([POSITION_FAILURE_CREATE_ROW]));

    expect($result->getCreated())->toBe(0)
        ->and($result->getSkipped())->toBe(1);
});

it('skips a row whose post insert returns zero', function () {
    when('wp_insert_post')->justReturn(0);
    $this->factory->shouldReceive('createNew')->andReturn(failingPosition(77));

    $result = ($this->importer)()->import(positionFailureCsv([POSITION_FAILURE_CREATE_ROW]));

    expect($result->getCreated())->toBe(0)
        ->and($result->getSkipped())->toBe(1);
});

it('records the error when an update by ID throws on save', function () {
    // Blank name + ID → the skip reason uses the "ID:" label, and the save
    // exception is recorded as the error detail.
    $this->repo->shouldReceive('findById')->with(5)->andReturn(failingPosition(5));
    $this->factory->shouldReceive('createNew')->andReturn(failingPosition(5));
    $this->repo->shouldReceive('save')->once()->andThrow(new \RuntimeException('update boom'));

    $result = ($this->importer)()->import(positionFailureCsv([
        ['5', '', 'chair@example.com', '24', '3', 'Desc', 'Summary'],
    ]));

    expect($result->getUpdated())->toBe(0)
        ->and($result->getSkipped())->toBe(1);
});

it('reports an unreadable file as an error', function () {
    // SpreadsheetReader throws for a missing file; the importer catches it,
    // records an error, and returns without any rows processed.
    $result = ($this->importer)()->import(sys_get_temp_dir() . '/does-not-exist-' . uniqid() . '.csv');

    expect($result->hasErrors())->toBeTrue()
        ->and($result->getCreated())->toBe(0);
});

it('skips an update by ID whose save fails, labelled with the ID', function () {
    // Row carries an ID but no position name, so the skip reason labels it
    // by ID; the save returning false records the failure.
    $this->repo->shouldReceive('findById')->with(5)->andReturn(failingPosition(5));
    $this->factory->shouldReceive('createNew')->andReturn(failingPosition(5));
    $this->repo->shouldReceive('save')->once()->andReturn(false);

    $result = ($this->importer)()->import(positionFailureCsv([
        ['5', '', 'chair@example.com', '24', '3', 'Desc', 'Summary'],
    ]));

    expect($result->getUpdated())->toBe(0)
        ->and($result->getSkipped())->toBe(1);
});

it('skips a name that resolves but cannot be loaded', function () {
    // The lookup resolves the name to an ID, but the position fails to load
    // from that ID — a data-integrity skip, not a create.
    $this->repo->shouldReceive('findById')->with(7)->andReturn(null);

    $importer = new PositionImporter(positionRepoResolving('Chair', 7), $this->factory);
    $result = $importer->import(positionFailureCsv([
        ['', 'Chair', 'chair@example.com', '24', '3', 'Desc', 'Summary'],
    ]));

    expect($result->getSkipped())->toBe(1);
});

it('falls back to the existing values for blank columns on update', function () {
    // Row carries an ID but leaves the optional columns blank; the importer
    // keeps the existing position's long name / description / summary
    // rather than overwriting them with empties.
    $this->repo->shouldReceive('findById')->with(5)->andReturn(failingPosition(5));
    $this->factory->shouldReceive('createNew')->andReturn(failingPosition(5));
    $this->repo->shouldReceive('save')->once()->andReturn(true);

    $result = ($this->importer)()->import(positionFailureCsv([
        ['5', '', 'chair@example.com', '', '', '', ''],
    ]));

    expect($result->getUpdated())->toBe(1);
});
