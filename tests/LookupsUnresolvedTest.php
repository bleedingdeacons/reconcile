<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Reconcile\Group\GroupLookup;
use Reconcile\Position\PositionLookup;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Positions\Interfaces\PositionRepository;

/**
 * The name → ID lookups' unresolved-name bookkeeping: a name that isn't in the
 * cache resolves to 0, is recorded for reporting, and the record can be reset.
 * A blank name short-circuits to 0 without touching the cache.
 *
 * @covers \Reconcile\Position\PositionLookup
 * @covers \Reconcile\Group\GroupLookup
 */
class LookupsUnresolvedTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @test */
    public function position_lookup_records_and_resets_unresolved_names(): void
    {
        $repo = Mockery::mock(PositionRepository::class);
        $repo->shouldReceive('findAll')->andReturn([]);
        $lookup = new PositionLookup($repo);

        $this->assertSame(0, $lookup->resolve('  '), 'blank resolves to 0 without a lookup');
        $this->assertSame(0, $lookup->resolve('Nonexistent Chair'));
        $this->assertContains('Nonexistent Chair', $lookup->getUnresolvedNames());

        $lookup->resetUnresolved();
        $this->assertSame([], $lookup->getUnresolvedNames());
    }

    /** @test */
    public function group_lookup_records_and_resets_unresolved_names(): void
    {
        $repo = Mockery::mock(GroupRepository::class);
        $repo->shouldReceive('findAll')->andReturn([]);
        $lookup = new GroupLookup($repo);

        $this->assertSame(0, $lookup->resolve('   '));
        $this->assertSame(0, $lookup->resolve('Nonexistent Group'));
        $this->assertContains('Nonexistent Group', $lookup->getUnresolvedNames());

        $lookup->resetUnresolved();
        $this->assertSame([], $lookup->getUnresolvedNames());
    }
}
