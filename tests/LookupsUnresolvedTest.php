<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Mockery;
use Reconcile\Group\GroupLookup;
use Reconcile\Position\PositionLookup;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Positions\Interfaces\PositionRepository;

/*
 * The name → ID lookups' unresolved-name bookkeeping: a name that isn't in the
 * cache resolves to 0, is recorded for reporting, and the record can be reset.
 * A blank name short-circuits to 0 without touching the cache.
 */

covers(PositionLookup::class, GroupLookup::class);

it('records and resets unresolved position names', function () {
    $repo = Mockery::mock(PositionRepository::class);
    $repo->shouldReceive('findAll')->andReturn([]);
    $lookup = new PositionLookup($repo);

    expect($lookup->resolve('  '))->toBe(0, 'blank resolves to 0 without a lookup')
        ->and($lookup->resolve('Nonexistent Chair'))->toBe(0)
        ->and($lookup->getUnresolvedNames())->toContain('Nonexistent Chair');

    $lookup->resetUnresolved();
    expect($lookup->getUnresolvedNames())->toBe([]);
});

it('records and resets unresolved group names', function () {
    $repo = Mockery::mock(GroupRepository::class);
    $repo->shouldReceive('findAll')->andReturn([]);
    $lookup = new GroupLookup($repo);

    expect($lookup->resolve('   '))->toBe(0)
        ->and($lookup->resolve('Nonexistent Group'))->toBe(0)
        ->and($lookup->getUnresolvedNames())->toContain('Nonexistent Group');

    $lookup->resetUnresolved();
    expect($lookup->getUnresolvedNames())->toBe([]);
});
