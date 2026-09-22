<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Mockery;
use Reconcile\Group\GroupLookup;
use Reconcile\Position\PositionLookup;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;

/*
 * Tests for the group and position name→ID lookups.
 */

covers(GroupLookup::class, PositionLookup::class);

function lookupGroup(int $id, string $title): Group
{
    $g = Mockery::mock(Group::class);
    $g->shouldReceive('getId')->andReturn($id);
    $g->shouldReceive('getTitle')->andReturn($title);
    return $g;
}

function lookupPosition(int $id, string $name): Position
{
    $p = Mockery::mock(Position::class);
    $p->shouldReceive('getId')->andReturn($id);
    $p->shouldReceive('getLongName')->andReturn($name);
    return $p;
}

// ─── GroupLookup ────────────────────────────────────────────────
describe('GroupLookup', function () {
    it('resolves case-insensitively and caches', function () {
        $repo = Mockery::mock(GroupRepository::class);
        // findAll must be hit only once thanks to the cache.
        $repo->shouldReceive('findAll')->once()->andReturn([
            lookupGroup(10, 'Tuesday Group'),
            lookupGroup(20, 'Thursday Group'),
        ]);

        $lookup = new GroupLookup($repo);

        expect($lookup->resolve('tuesday group'))->toBe(10)
            ->and($lookup->resolve('  THURSDAY GROUP '))->toBe(20)
            // Second resolve of the same value does not rebuild the cache.
            ->and($lookup->resolve('Tuesday Group'))->toBe(10);
    });

    it('returns zero for an empty or unknown name and records it as unresolved', function () {
        $repo = Mockery::mock(GroupRepository::class);
        $repo->shouldReceive('findAll')->andReturn([lookupGroup(10, 'Known')]);

        $lookup = new GroupLookup($repo);

        expect($lookup->resolve('   '))->toBe(0)
            ->and($lookup->resolve('Unknown Group'))->toBe(0)
            ->and($lookup->getUnresolvedNames())->toBe(['Unknown Group']);

        $lookup->resetUnresolved();
        expect($lookup->getUnresolvedNames())->toBe([]);
    });

    it('tolerates a null repository', function () {
        $lookup = new GroupLookup(null);

        expect($lookup->resolve('Anything'))->toBe(0);
    });

    it('survives a repository exception', function () {
        $repo = Mockery::mock(GroupRepository::class);
        $repo->shouldReceive('findAll')->andThrow(new \RuntimeException('db down'));

        $lookup = new GroupLookup($repo);

        expect($lookup->resolve('Anything'))->toBe(0);
    });

    it('rebuilds after invalidateCache()', function () {
        $repo = Mockery::mock(GroupRepository::class);
        $repo->shouldReceive('findAll')->twice()->andReturn([lookupGroup(10, 'Known')]);

        $lookup = new GroupLookup($repo);
        expect($lookup->resolve('Known'))->toBe(10);

        $lookup->invalidateCache();
        // A second findAll happens because the cache was invalidated.
        expect($lookup->resolve('Known'))->toBe(10);
    });
});

// ─── PositionLookup ─────────────────────────────────────────────
describe('PositionLookup', function () {
    it('resolves by long name', function () {
        $repo = Mockery::mock(PositionRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([
            lookupPosition(5, 'Intergroup Chair'),
        ]);

        $lookup = new PositionLookup($repo);

        expect($lookup->resolve('intergroup chair'))->toBe(5)
            ->and($lookup->resolve('Nonexistent'))->toBe(0)
            ->and($lookup->getUnresolvedNames())->toBe(['Nonexistent']);
    });

    it('tolerates a null repository', function () {
        expect((new PositionLookup(null))->resolve('Chair'))->toBe(0);
    });

    it('survives a repository exception', function () {
        $repo = Mockery::mock(PositionRepository::class);
        $repo->shouldReceive('findAll')->andThrow(new \RuntimeException('db down'));

        expect((new PositionLookup($repo))->resolve('Chair'))->toBe(0);
    });

    it('resets unresolved names and rebuilds after invalidateCache()', function () {
        $repo = Mockery::mock(PositionRepository::class);
        $repo->shouldReceive('findAll')->twice()->andReturn([lookupPosition(5, 'Chair')]);

        $lookup = new PositionLookup($repo);

        expect($lookup->resolve('Unknown'))->toBe(0)
            ->and($lookup->getUnresolvedNames())->toBe(['Unknown']);
        $lookup->resetUnresolved();
        expect($lookup->getUnresolvedNames())->toBe([]);

        // invalidateCache forces a second findAll on the next resolve.
        $lookup->invalidateCache();
        expect($lookup->resolve('Chair'))->toBe(5);
    });
});
