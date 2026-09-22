<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Unity\Contacts\Interfaces\Contact;
use function Brain\Monkey\Functions\when;
use Mockery;
use BleedingDeacons\WpMocks\WpState;
use Reconcile\Group\GroupImporter;
use Unity\Contacts\Interfaces\ContactFactory;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupFactory;
use Unity\Groups\Interfaces\GroupRepository;

/*
 * Row-level and dependency tests for GroupImporter (the changing-event
 * behaviour is covered separately in GroupImporterChangingEventTest).
 */

covers(GroupImporter::class);

const GROUP_ROWS_HEADERS = [
    'Group ID', 'Group Name', 'Group Email',
    'Contact 1 Name', 'Contact 1 Email', 'Contact 1 Phone',
];

/**
 * @param array<int, array<int, string>> $rows
 */
function groupRowsCsv(array $headers, array $rows): string
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
function importedGroup(int $id = 5)
{
    $g = Mockery::mock(Group::class);
    $g->shouldReceive('getId')->andReturn($id);
    $g->shouldReceive('getTitle')->andReturn('Tuesday Group');
    $g->shouldReceive('isValid')->andReturn(true);
    return $g;
}

beforeEach(function () {
    $this->repo = Mockery::mock(GroupRepository::class);
    $this->factory = Mockery::mock(GroupFactory::class);
    $this->contactFactory = Mockery::mock(ContactFactory::class);
    $this->repo->shouldReceive('findAll')->andReturn([])->byDefault();

    $this->importer = function (): GroupImporter {
        return new GroupImporter($this->repo, $this->factory, $this->contactFactory);
    };
});

it('reports a null repository as an error', function () {
    $result = (new GroupImporter(null, $this->factory, $this->contactFactory))
        ->import(groupRowsCsv(GROUP_ROWS_HEADERS, []));
    expect($result->hasErrors())->toBeTrue();
});

it('reports a null factory as an error', function () {
    $result = (new GroupImporter($this->repo, null, $this->contactFactory))
        ->import(groupRowsCsv(GROUP_ROWS_HEADERS, []));
    expect($result->hasErrors())->toBeTrue();
});

it('reports a missing email column as an error', function () {
    // Group ID present but no Group Email column.
    $result = ($this->importer)()->import(groupRowsCsv(['Group ID'], [['5']]));
    expect($result->hasErrors())->toBeTrue();
});

it('reports missing identifier columns as an error', function () {
    // Email present, but neither Group ID nor Group Name.
    $result = ($this->importer)()->import(groupRowsCsv(['Group Email'], [['a@b.com']]));
    expect($result->hasErrors())->toBeTrue();
});

it('skips a row with an empty ID and name', function () {
    $result = ($this->importer)()->import(
        groupRowsCsv(GROUP_ROWS_HEADERS, [['', '', 'e@example.com', '', '', '']])
    );
    expect($result->getSkipped())->toBe(1);
});

it('skips a non-numeric ID', function () {
    $result = ($this->importer)()->import(
        groupRowsCsv(GROUP_ROWS_HEADERS, [['abc', 'Tuesday', 'e@example.com', '', '', '']])
    );
    expect($result->getSkipped())->toBe(1);
});

it('skips an ID that does not exist', function () {
    $this->repo->shouldReceive('findById')->with(99)->andReturn(null);

    $result = ($this->importer)()->import(
        groupRowsCsv(GROUP_ROWS_HEADERS, [['99', 'Tuesday', 'e@example.com', '', '', '']])
    );
    expect($result->getSkipped())->toBe(1);
});

it('skips a name resolving to an unloadable group', function () {
    // The lookup resolves "Tuesday" to id 5, but findById(5) returns null,
    // so the row is skipped rather than silently created.
    $match = Mockery::mock(Group::class);
    $match->shouldReceive('getId')->andReturn(5);
    $match->shouldReceive('getTitle')->andReturn('Tuesday');
    $this->repo->shouldReceive('findAll')->andReturn([$match]);
    $this->repo->shouldReceive('findById')->with(5)->andReturn(null);

    $result = ($this->importer)()->import(
        groupRowsCsv(GROUP_ROWS_HEADERS, [['', 'Tuesday', 'e@example.com', '', '', '']])
    );

    expect($result->getSkipped())->toBe(1);
});

it('counts an existing group as an update on a dry run', function () {
    $this->repo->shouldReceive('findById')->with(5)->andReturn(importedGroup(5));

    $result = ($this->importer)()->import(
        groupRowsCsv(GROUP_ROWS_HEADERS, [['5', 'Tuesday', 'e@example.com', '', '', '']]),
        true
    );

    expect($result->getUpdated())->toBe(1);
});

it('counts an unresolved name as a create on a dry run', function () {
    $result = ($this->importer)()->import(
        groupRowsCsv(GROUP_ROWS_HEADERS, [['', 'Brand New', 'e@example.com', '', '', '']]),
        true
    );

    expect($result->getCreated())->toBe(1);
});

it('creates a new group with contacts', function () {
    // A named group that does not resolve is created via wp_insert_post,
    // exercising contact building and the meta-field writes.
    WpState::$nextPostId = 88;

    $this->contactFactory->shouldReceive('create')
        ->andReturnUsing(function ($name, $email, $phone) {
            $c = Mockery::mock(Contact::class);
            $c->shouldReceive('getName')->andReturn($name);
            $c->shouldReceive('getEmail')->andReturn($email);
            $c->shouldReceive('getPhone')->andReturn($phone);
            return $c;
        });

    $result = ($this->importer)()->import(
        groupRowsCsv(GROUP_ROWS_HEADERS, [
            ['', 'Brand New', 'e@example.com', 'Alice', 'alice@example.com', '0700'],
        ])
    );

    expect($result->getCreated())->toBe(1)
        ->and($result->getSkipped())->toBe(0);
});

it('skips a create whose post insert fails', function () {
    when('wp_insert_post')->justReturn(0);
    $this->contactFactory->shouldReceive('create')->andReturnUsing(
        fn () => Mockery::mock(Contact::class)->shouldIgnoreMissing()
    );

    $result = ($this->importer)()->import(
        groupRowsCsv(GROUP_ROWS_HEADERS, [['', 'Brand New', 'e@example.com', '', '', '']])
    );

    expect($result->getSkipped())->toBe(1)
        ->and($result->getCreated())->toBe(0);
});

it('processes multiple rows in one file', function () {
    $existing = Mockery::mock(Group::class)->shouldIgnoreMissing();
    $existing->shouldReceive('getId')->andReturn(5);
    $existing->shouldReceive('isValid')->andReturn(true);
    $this->repo->shouldReceive('findById')->with(5)->andReturn($existing);
    WpState::$nextPostId = 90;
    $this->contactFactory->shouldReceive('create')->andReturnUsing(
        fn () => Mockery::mock(Contact::class)->shouldIgnoreMissing()
    );

    $result = ($this->importer)()->import(groupRowsCsv(GROUP_ROWS_HEADERS, [
        ['5', 'Tuesday', 'e@example.com', '', '', ''],   // update
        ['', '', 'x@example.com', '', '', ''],           // skip (no id/name)
        ['', 'Fresh Group', 'f@example.com', '', '', ''], // create
    ]));

    expect($result->getTotalRows())->toBe(3)
        ->and($result->getUpdated())->toBe(1)
        ->and($result->getCreated())->toBe(1)
        ->and($result->getSkipped())->toBe(1);
});

it('updates an existing group', function () {
    $existing = Mockery::mock(Group::class)->shouldIgnoreMissing();
    $existing->shouldReceive('getId')->andReturn(5);
    $existing->shouldReceive('isValid')->andReturn(true);
    $this->repo->shouldReceive('findById')->with(5)->andReturn($existing);

    $this->contactFactory->shouldReceive('create')->andReturnUsing(function ($name, $email, $phone) {
        return Mockery::mock(Contact::class)->shouldIgnoreMissing();
    });

    $result = ($this->importer)()->import(
        groupRowsCsv(GROUP_ROWS_HEADERS, [
            ['5', 'Tuesday', 'e@example.com', 'Alice', 'alice@example.com', '0700'],
        ])
    );

    expect($result->getUpdated())->toBe(1);
});
