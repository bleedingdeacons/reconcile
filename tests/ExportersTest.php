<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Reconcile\Group\GroupExporter;
use Reconcile\Member\MemberExporter;
use Reconcile\Position\PositionExporter;
use Unity\Contacts\Interfaces\Contact;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;

/**
 * Tests for the three CSV exporters.
 *
 * @covers \Reconcile\Member\MemberExporter
 * @covers \Reconcile\Group\GroupExporter
 * @covers \Reconcile\Position\PositionExporter
 */
class ExportersTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── MemberExporter ─────────────────────────────────────────────

    /**
     * @test
     */
    public function member_export_writes_a_header_and_resolves_related_names(): void
    {
        $member = Mockery::mock(Member::class);
        $member->shouldReceive('getId')->andReturn(1);
        $member->shouldReceive('getAnonymousName')->andReturn('Jane D.');
        $member->shouldReceive('getHomeGroup')->andReturn(10);
        $member->shouldReceive('getPersonalEmail')->andReturn('jane@example.com');
        $member->shouldReceive('getMobileNumber')->andReturn('07700 900000');
        $member->shouldReceive('isGSR')->andReturn(true);
        $member->shouldReceive('getIntergroupPosition')->andReturn(5);
        $member->shouldReceive('getIntergroupPositionRotation')->andReturn('2026-01-01');
        $member->shouldReceive('isTwelfthStepper')->andReturn(false);
        $member->shouldReceive('getArea')->andReturn('North');
        $member->shouldReceive('getAccepts')->andReturn(['accepts-male', 'accepts-female']);

        $memberRepo = Mockery::mock(MemberRepository::class);
        $memberRepo->shouldReceive('findAll')->andReturn([$member]);

        $group = Mockery::mock(Group::class);
        $group->shouldReceive('getId')->andReturn(10);
        $group->shouldReceive('getTitle')->andReturn('Tuesday Group');
        $groupRepo = Mockery::mock(GroupRepository::class);
        $groupRepo->shouldReceive('findAll')->andReturn([$group]);

        $position = Mockery::mock(Position::class);
        $position->shouldReceive('getId')->andReturn(5);
        $position->shouldReceive('getLongName')->andReturn('Chair');
        $positionRepo = Mockery::mock(PositionRepository::class);
        $positionRepo->shouldReceive('findAll')->andReturn([$position]);

        $csv = (new MemberExporter($memberRepo, $groupRepo, $positionRepo))->export();

        $this->assertStringContainsString('Anonymous Name', $csv);
        $this->assertStringContainsString('Jane D.', $csv);
        // IDs resolved to names.
        $this->assertStringContainsString('Tuesday Group', $csv);
        $this->assertStringContainsString('Chair', $csv);
        // Accepts labels joined with a pipe.
        $this->assertStringContainsString('Male|Female', $csv);
        // Boolean flags rendered as Yes/No.
        $this->assertStringContainsString('Yes', $csv);
    }

    /**
     * @test
     */
    public function member_export_sanitises_formula_injection_and_passes_unknown_accepts_through(): void
    {
        $member = Mockery::mock(Member::class);
        $member->shouldReceive('getId')->andReturn(2);
        // A name beginning with '=' is a CSV formula-injection vector.
        $member->shouldReceive('getAnonymousName')->andReturn('=SUM(A1)');
        $member->shouldReceive('getHomeGroup')->andReturn(0);
        $member->shouldReceive('getPersonalEmail')->andReturn('x@example.com');
        $member->shouldReceive('getMobileNumber')->andReturn('555');
        $member->shouldReceive('isGSR')->andReturn(false);
        $member->shouldReceive('getIntergroupPosition')->andReturn(0);
        $member->shouldReceive('getIntergroupPositionRotation')->andReturn('');
        $member->shouldReceive('isTwelfthStepper')->andReturn(false);
        $member->shouldReceive('getArea')->andReturn('');
        // An accepts value with no label mapping is passed through verbatim.
        $member->shouldReceive('getAccepts')->andReturn(['accepts-mystery']);

        $memberRepo = Mockery::mock(MemberRepository::class);
        $memberRepo->shouldReceive('findAll')->andReturn([$member]);

        $csv = (new MemberExporter($memberRepo, null, null))->export();

        // The formula is neutralised with a leading single quote.
        $this->assertStringContainsString("'=SUM(A1)", $csv);
        $this->assertStringContainsString('accepts-mystery', $csv);
    }

    /**
     * @test
     */
    public function member_export_throws_without_a_repository(): void
    {
        $this->expectException(\RuntimeException::class);
        (new MemberExporter(null, null, null))->export();
    }

    // ─── GroupExporter ──────────────────────────────────────────────

    /**
     * @test
     */
    public function group_export_writes_contacts(): void
    {
        $contact = Mockery::mock(Contact::class);
        $contact->shouldReceive('getName')->andReturn('Alice');
        $contact->shouldReceive('getEmail')->andReturn('alice@example.com');
        $contact->shouldReceive('getPhone')->andReturn('0700');

        $group = Mockery::mock(Group::class);
        $group->shouldReceive('getId')->andReturn(10);
        $group->shouldReceive('getTitle')->andReturn('Tuesday Group');
        $group->shouldReceive('getEmail')->andReturn('group@example.com');
        $group->shouldReceive('getContacts')->andReturn([$contact]);

        $repo = Mockery::mock(GroupRepository::class);
        $repo->shouldReceive('findAll')->andReturn([$group]);

        $csv = (new GroupExporter($repo))->export();

        $this->assertStringContainsString('Tuesday Group', $csv);
        $this->assertStringContainsString('group@example.com', $csv);
        $this->assertStringContainsString('Alice', $csv);
    }

    /**
     * @test
     */
    public function group_export_throws_without_a_repository(): void
    {
        $this->expectException(\RuntimeException::class);
        (new GroupExporter(null))->export();
    }

    // ─── PositionExporter ───────────────────────────────────────────

    /**
     * @test
     */
    public function position_export_writes_position_rows(): void
    {
        $position = Mockery::mock(Position::class);
        $position->shouldReceive('getId')->andReturn(5);
        $position->shouldReceive('getLongName')->andReturn('Chair');
        $position->shouldReceive('getEmail')->andReturn('chair@example.com');
        $position->shouldReceive('getMinimumSobriety')->andReturn(24);
        $position->shouldReceive('getTermYears')->andReturn(3);
        $position->shouldReceive('getShortDescription')->andReturn('Chairs');
        $position->shouldReceive('getSummary')->andReturn('Runs intergroup');

        $repo = Mockery::mock(PositionRepository::class);
        $repo->shouldReceive('findAll')->andReturn([$position]);

        $csv = (new PositionExporter($repo))->export();

        $this->assertStringContainsString('Chair', $csv);
        $this->assertStringContainsString('chair@example.com', $csv);
        $this->assertStringContainsString('Runs intergroup', $csv);
    }

    /**
     * @test
     */
    public function position_export_throws_without_a_repository(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PositionExporter(null))->export();
    }
}
