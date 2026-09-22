<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Mockery;
use Reconcile\Group\GroupExporter;
use Reconcile\Member\MemberExporter;
use Reconcile\Position\PositionExporter;
use RuntimeException;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Positions\Interfaces\PositionRepository;

/*
 * Each exporter refuses to run when its primary Unity repository is not
 * available (an unconfigured/partly-loaded Unity), throwing rather than
 * emitting an empty or malformed CSV.
 */

covers(MemberExporter::class, PositionExporter::class, GroupExporter::class);

it('throws from the member exporter without a member repository', function () {
    $exporter = new MemberExporter(
        null,
        Mockery::mock(GroupRepository::class),
        Mockery::mock(PositionRepository::class),
    );

    $exporter->export();
})->throws(RuntimeException::class);

it('throws from the position exporter without a position repository', function () {
    (new PositionExporter(null))->export();
})->throws(RuntimeException::class);

it('throws from the group exporter without a group repository', function () {
    (new GroupExporter(null))->export();
})->throws(RuntimeException::class);
