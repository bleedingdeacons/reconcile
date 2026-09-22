<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Mockery;
use Reconcile\Group\GroupExporter;
use Reconcile\Member\MemberExporter;
use Reconcile\Position\PositionExporter;
use Unity\Contacts\Interfaces\Contact;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\PreferredContact;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;

/*
 * Tests for the three CSV exporters.
 */

covers(MemberExporter::class, GroupExporter::class, PositionExporter::class);

// ─── MemberExporter ─────────────────────────────────────────────
describe('MemberExporter', function () {
    it('member export writes a header and resolves related names', function () {
        $member = Mockery::mock(Member::class);
        $member->shouldReceive('getId')->andReturn(1);
        $member->shouldReceive('getAnonymousName')->andReturn('Jane D.');
        $member->shouldReceive('getHomeGroup')->andReturn(10);
        $member->shouldReceive('getPersonalEmail')->andReturn('jane@example.com');
        $member->shouldReceive('getMobileNumber')->andReturn('07700 900000');
        $member->shouldReceive('getLandlineNumber')->andReturn('0117 496 0000');
        $member->shouldReceive('getPreferredContact')->andReturn(PreferredContact::Landline);
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

        expect($csv)->toContain('Anonymous Name')
            ->toContain('Jane D.')
            // The export is the import's own column set, so both new columns
            // appear in the header and carry a value.
            ->toContain('Landline Number')
            ->toContain('Preferred Contact')
            ->toContain('0117 496 0000')
            ->toContain('Landline')
            // IDs resolved to names.
            ->toContain('Tuesday Group')
            ->toContain('Chair')
            // Accepts labels joined with a pipe.
            ->toContain('Male|Female')
            // Boolean flags rendered as Yes/No.
            ->toContain('Yes');
    });

    it('member export sanitises formula injection and passes unknown accepts through', function () {
        $member = Mockery::mock(Member::class);
        $member->shouldReceive('getId')->andReturn(2);
        // A name beginning with '=' is a CSV formula-injection vector.
        $member->shouldReceive('getAnonymousName')->andReturn('=SUM(A1)');
        $member->shouldReceive('getHomeGroup')->andReturn(0);
        $member->shouldReceive('getPersonalEmail')->andReturn('x@example.com');
        $member->shouldReceive('getMobileNumber')->andReturn('555');
        $member->shouldReceive('getLandlineNumber')->andReturn('');
        $member->shouldReceive('getPreferredContact')->andReturn(PreferredContact::Mobile);
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
        expect($csv)->toContain("'=SUM(A1)")
            ->toContain('accepts-mystery');
    });

    it('member export throws without a repository', function () {
        (new MemberExporter(null, null, null))->export();
    })->throws(\RuntimeException::class);
});

// ─── GroupExporter ──────────────────────────────────────────────
describe('GroupExporter', function () {
    it('group export writes contacts', function () {
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

        expect($csv)->toContain('Tuesday Group')
            ->toContain('group@example.com')
            ->toContain('Alice');
    });

    it('group export throws without a repository', function () {
        (new GroupExporter(null))->export();
    })->throws(\RuntimeException::class);
});

// ─── PositionExporter ───────────────────────────────────────────
describe('PositionExporter', function () {
    it('position export writes position rows', function () {
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

        expect($csv)->toContain('Chair')
            ->toContain('chair@example.com')
            ->toContain('Runs intergroup');
    });

    it('position export throws without a repository', function () {
        (new PositionExporter(null))->export();
    })->throws(\RuntimeException::class);
});
