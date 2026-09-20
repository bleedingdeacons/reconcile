<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Mockery;
use BleedingDeacons\WpMocks\TestCase;
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
 */
#[CoversClass(\Reconcile\Member\MemberExporter::class)]
#[CoversClass(\Reconcile\Position\PositionExporter::class)]
#[CoversClass(\Reconcile\Group\GroupExporter::class)]
class ExportersNullRepositoryTest extends TestCase
{
    #[Test]
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

    #[Test]
    public function position_exporter_throws_without_a_position_repository(): void
    {
        $this->expectException(RuntimeException::class);
        (new PositionExporter(null))->export();
    }

    #[Test]
    public function group_exporter_throws_without_a_group_repository(): void
    {
        $this->expectException(RuntimeException::class);
        (new GroupExporter(null))->export();
    }
}
