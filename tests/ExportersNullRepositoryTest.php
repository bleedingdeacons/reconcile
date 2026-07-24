<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Reconcile\Group\GroupExporter;
use Reconcile\Member\MemberExporter;
use Reconcile\Position\PositionExporter;
use RuntimeException;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Positions\Interfaces\PositionRepository;

/**
 * Each exporter refuses to run when its primary Unity repository is not
 * available (an unconfigured/partly-loaded Unity), throwing rather than
 * emitting an empty or malformed CSV.
 *
 * @covers \Reconcile\Member\MemberExporter
 * @covers \Reconcile\Position\PositionExporter
 * @covers \Reconcile\Group\GroupExporter
 */
class ExportersNullRepositoryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @test */
    public function member_exporter_throws_without_a_member_repository(): void
    {
        $exporter = new MemberExporter(
            null,
            Mockery::mock(GroupRepository::class),
            Mockery::mock(PositionRepository::class),
        );

        $this->expectException(RuntimeException::class);
        $exporter->export();
    }

    /** @test */
    public function position_exporter_throws_without_a_position_repository(): void
    {
        $this->expectException(RuntimeException::class);
        (new PositionExporter(null))->export();
    }

    /** @test */
    public function group_exporter_throws_without_a_group_repository(): void
    {
        $this->expectException(RuntimeException::class);
        (new GroupExporter(null))->export();
    }
}
