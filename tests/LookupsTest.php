<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Reconcile\Group\GroupLookup;
use Reconcile\Position\PositionLookup;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;

/**
 * Tests for the group and position name→ID lookups.
 *
 * @covers \Reconcile\Group\GroupLookup
 * @covers \Reconcile\Position\PositionLookup
 */
class LookupsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function group(int $id, string $title): Group
    {
        $g = Mockery::mock(Group::class);
        $g->shouldReceive('getId')->andReturn($id);
        $g->shouldReceive('getTitle')->andReturn($title);
        return $g;
    }

    private function position(int $id, string $name): Position
    {
        $p = Mockery::mock(Position::class);
        $p->shouldReceive('getId')->andReturn($id);
        $p->shouldReceive('getLongName')->andReturn($name);
        return $p;
    }

    // ─── GroupLookup ────────────────────────────────────────────────

    /**
     * @test
     */
    public function group_resolve_matches_case_insensitively_and_caches(): void
    {
        $repo = Mockery::mock(GroupRepository::class);
        // findAll must be hit only once thanks to the cache.
        $repo->shouldReceive('findAll')->once()->andReturn([
            $this->group(10, 'Tuesday Group'),
            $this->group(20, 'Thursday Group'),
        ]);

        $lookup = new GroupLookup($repo);

        $this->assertSame(10, $lookup->resolve('tuesday group'));
        $this->assertSame(20, $lookup->resolve('  THURSDAY GROUP '));
        // Second resolve of the same value does not rebuild the cache.
        $this->assertSame(10, $lookup->resolve('Tuesday Group'));
    }

    /**
     * @test
     */
    public function group_resolve_returns_zero_for_empty_or_unknown_and_records_unresolved(): void
    {
        $repo = Mockery::mock(GroupRepository::class);
        $repo->shouldReceive('findAll')->andReturn([$this->group(10, 'Known')]);

        $lookup = new GroupLookup($repo);

        $this->assertSame(0, $lookup->resolve('   '));
        $this->assertSame(0, $lookup->resolve('Unknown Group'));
        $this->assertSame(['Unknown Group'], $lookup->getUnresolvedNames());

        $lookup->resetUnresolved();
        $this->assertSame([], $lookup->getUnresolvedNames());
    }

    /**
     * @test
     */
    public function group_lookup_tolerates_a_null_repository(): void
    {
        $lookup = new GroupLookup(null);

        $this->assertSame(0, $lookup->resolve('Anything'));
    }

    /**
     * @test
     */
    public function group_lookup_survives_a_repository_exception(): void
    {
        $repo = Mockery::mock(GroupRepository::class);
        $repo->shouldReceive('findAll')->andThrow(new \RuntimeException('db down'));

        $lookup = new GroupLookup($repo);

        $this->assertSame(0, $lookup->resolve('Anything'));
    }

    /**
     * @test
     */
    public function group_invalidate_cache_forces_a_rebuild(): void
    {
        $repo = Mockery::mock(GroupRepository::class);
        $repo->shouldReceive('findAll')->twice()->andReturn([$this->group(10, 'Known')]);

        $lookup = new GroupLookup($repo);
        $this->assertSame(10, $lookup->resolve('Known'));

        $lookup->invalidateCache();
        // A second findAll happens because the cache was invalidated.
        $this->assertSame(10, $lookup->resolve('Known'));
    }

    // ─── PositionLookup ─────────────────────────────────────────────

    /**
     * @test
     */
    public function position_resolve_matches_by_long_name(): void
    {
        $repo = Mockery::mock(PositionRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([
            $this->position(5, 'Intergroup Chair'),
        ]);

        $lookup = new PositionLookup($repo);

        $this->assertSame(5, $lookup->resolve('intergroup chair'));
        $this->assertSame(0, $lookup->resolve('Nonexistent'));
        $this->assertSame(['Nonexistent'], $lookup->getUnresolvedNames());
    }

    /**
     * @test
     */
    public function position_lookup_tolerates_a_null_repository(): void
    {
        $this->assertSame(0, (new PositionLookup(null))->resolve('Chair'));
    }

    /**
     * @test
     */
    public function position_lookup_survives_a_repository_exception(): void
    {
        $repo = Mockery::mock(PositionRepository::class);
        $repo->shouldReceive('findAll')->andThrow(new \RuntimeException('db down'));

        $this->assertSame(0, (new PositionLookup($repo))->resolve('Chair'));
    }

    /**
     * @test
     */
    public function position_lookup_reset_and_invalidate_behave(): void
    {
        $repo = Mockery::mock(PositionRepository::class);
        $repo->shouldReceive('findAll')->twice()->andReturn([$this->position(5, 'Chair')]);

        $lookup = new PositionLookup($repo);

        $this->assertSame(0, $lookup->resolve('Unknown'));
        $this->assertSame(['Unknown'], $lookup->getUnresolvedNames());
        $lookup->resetUnresolved();
        $this->assertSame([], $lookup->getUnresolvedNames());

        // invalidateCache forces a second findAll on the next resolve.
        $lookup->invalidateCache();
        $this->assertSame(5, $lookup->resolve('Chair'));
    }
}
