<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use function Brain\Monkey\Functions\when;
use Mockery;
use Reconcile\Group\GroupImporter;
use Unity\Contacts\Interfaces\ContactFactory;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupFactory;
use Unity\Groups\Interfaces\GroupRepository;

/*
 * GroupImporter failure branches: an unreadable file, and the WordPress
 * post insert/update returning a WP_Error. GroupImporter persists through
 * wp_insert_post/wp_update_post directly (rather than a repository), so the
 * error stubs are driven via the bootstrap globals.
 */

covers(GroupImporter::class);

const GROUP_FAILURE_HEADERS = [
    'Group ID', 'Group Name', 'Group Email',
    'Contact 1 Name', 'Contact 1 Email', 'Contact 1 Phone',
];

const GROUP_FAILURE_CREATE_ROW = ['', 'New Group', 'g@example.com', 'Sam', 's@example.com', '555'];

/** @param array<int, array<int, string>> $rows */
function groupFailureCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'grp_fail_') . '.csv';
    $handle = fopen($path, 'w');
    fputcsv($handle, GROUP_FAILURE_HEADERS, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }
    fclose($handle);
    return $path;
}

/** @return Group&Mockery\MockInterface */
function failingGroup(int $id = 5)
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

it('reports an unreadable file as an error', function () {
    $result = ($this->importer)()->import(sys_get_temp_dir() . '/missing-' . uniqid() . '.csv');

    expect($result->hasErrors())->toBeTrue()
        ->and($result->getCreated())->toBe(0);
});

it('skips a row whose post insert returns a WP_Error', function () {
    when('wp_insert_post')->justReturn(new \WP_Error('insert_failed', 'insert refused'));
    $this->factory->shouldReceive('createNew')->andReturn(failingGroup(0))->byDefault();

    $result = ($this->importer)()->import(groupFailureCsv([GROUP_FAILURE_CREATE_ROW]));

    expect($result->getCreated())->toBe(0)
        ->and($result->getSkipped())->toBe(1);
});

it('skips a row whose post insert returns zero', function () {
    when('wp_insert_post')->justReturn(0);
    $this->factory->shouldReceive('createNew')->andReturn(failingGroup(0))->byDefault();

    $result = ($this->importer)()->import(groupFailureCsv([GROUP_FAILURE_CREATE_ROW]));

    expect($result->getCreated())->toBe(0)
        ->and($result->getSkipped())->toBe(1);
});

it('swallows a repository exception on an ID lookup and skips', function () {
    // findById() throwing is caught and returns null, so the ID row is
    // treated as "group not found" and skipped.
    $this->repo->shouldReceive('findById')->with(9)->andThrow(new \RuntimeException('db down'));

    $result = ($this->importer)()->import(groupFailureCsv([
        ['9', 'Ghost Group', 'g@example.com', 'Sam', 's@example.com', '555'],
    ]));

    expect($result->getSkipped())->toBe(1);
});

it('skips a row whose post update returns a WP_Error', function () {
    when('wp_update_post')->justReturn(new \WP_Error('update_failed', 'update refused'));
    $this->repo->shouldReceive('findById')->with(5)->andReturn(failingGroup(5));
    $this->factory->shouldReceive('createNew')->andReturn(failingGroup(5))->byDefault();

    $result = ($this->importer)()->import(groupFailureCsv([
        ['5', 'Existing Group', 'g@example.com', 'Sam', 's@example.com', '555'],
    ]));

    expect($result->getUpdated())->toBe(0)
        ->and($result->getSkipped())->toBe(1);
});

it('skips a create whose meta write throws', function () {
    when('update_post_meta')->alias(static function (): bool {
        throw new \RuntimeException('meta write failed');
    });
    $this->factory->shouldReceive('createNew')->andReturn(failingGroup(0))->byDefault();

    $result = ($this->importer)()->import(groupFailureCsv([GROUP_FAILURE_CREATE_ROW]));

    expect($result->getCreated())->toBe(0)
        ->and($result->getSkipped())->toBe(1);
});

it('skips an update whose meta write throws', function () {
    when('update_post_meta')->alias(static function (): bool {
        throw new \RuntimeException('meta write failed');
    });
    $this->repo->shouldReceive('findById')->with(5)->andReturn(failingGroup(5));
    $this->factory->shouldReceive('createNew')->andReturn(failingGroup(5))->byDefault();

    $result = ($this->importer)()->import(groupFailureCsv([
        ['5', 'Existing Group', 'g@example.com', 'Sam', 's@example.com', '555'],
    ]));

    expect($result->getUpdated())->toBe(0)
        ->and($result->getSkipped())->toBe(1);
});

it('captures a meta write warning on create', function () {
    // A PHP warning during the meta write is captured by the save wrapper's
    // error handler; the save still succeeds.
    when('update_post_meta')->alias(static function (): bool {
        trigger_error('meta write warning', E_USER_WARNING);

        return true;
    });
    $this->factory->shouldReceive('createNew')->andReturn(failingGroup(1))->byDefault();

    $result = ($this->importer)()->import(groupFailureCsv([GROUP_FAILURE_CREATE_ROW]));

    expect($result->getCreated())->toBe(1);
});

it('captures a meta write warning on update', function () {
    when('update_post_meta')->alias(static function (): bool {
        trigger_error('meta write warning', E_USER_WARNING);

        return true;
    });
    $this->repo->shouldReceive('findById')->with(5)->andReturn(failingGroup(5));
    $this->factory->shouldReceive('createNew')->andReturn(failingGroup(5))->byDefault();

    $result = ($this->importer)()->import(groupFailureCsv([
        ['5', 'Existing Group', 'g@example.com', 'Sam', 's@example.com', '555'],
    ]));

    expect($result->getUpdated())->toBe(1);
});
