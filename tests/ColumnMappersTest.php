<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Reconcile\Group\GroupColumnMapper;
use Reconcile\Member\MemberColumnMapper;
use Reconcile\Position\PositionColumnMapper;

/*
 * Tests for the three spreadsheet column mappers.
 */

covers(MemberColumnMapper::class, GroupColumnMapper::class, PositionColumnMapper::class);

// ─── MemberColumnMapper ─────────────────────────────────────────
describe('MemberColumnMapper', function () {
    it('maps normalised aliases to canonical properties', function () {
        $mapper = new MemberColumnMapper();

        $mapping = $mapper->mapHeaders([
            'Anonymous Name', 'Home Group', 'Personal Email', 'Mobile',
            'GSR', 'Intergroup Position', 'Unknown Column', '12th Stepper',
        ]);

        expect($mapping[0])->toBe('anonymous_name')
            ->and($mapping[1])->toBe('home_group')
            ->and($mapping[2])->toBe('personal_email')
            ->and($mapping[3])->toBe('mobile_number')
            ->and($mapping[4])->toBe('is_gsr')
            ->and($mapping[5])->toBe('intergroup_position')
            // Unknown headers are simply not mapped.
            ->and($mapping)->not->toHaveKey(6)
            ->and($mapping[7])->toBe('is_twelfth_stepper');
    });

    it('reports missing required columns on validate', function () {
        $mapper = new MemberColumnMapper();

        $missing = $mapper->validateMapping([0 => 'anonymous_name']);

        expect($missing)->toContain('home_group')
            ->toContain('personal_email')
            ->not->toContain('anonymous_name');
    });

    it('passes validate when all required columns are present', function () {
        $mapper = new MemberColumnMapper();
        $full = ['anonymous_name', 'home_group', 'personal_email', 'mobile_number', 'is_gsr', 'intergroup_position'];

        expect($mapper->validateMapping(array_values($full)))->toBe([]);
    });

    it('maps the landline and preferred contact columns', function () {
        $mapper = new MemberColumnMapper();

        $mapping = $mapper->mapHeaders([
            'Landline Number', 'landline', 'Preferred Contact', 'preferred_contact',
        ]);

        expect($mapping[0])->toBe('landline_number')
            ->and($mapping[1])->toBe('landline_number')
            ->and($mapping[2])->toBe('preferred_contact')
            ->and($mapping[3])->toBe('preferred_contact');
    });

    // Both columns must stay optional. Requiring either would reject every
    // spreadsheet written before they existed.
    it('does not require the landline and preferred contact columns', function () {
        $mapper = new MemberColumnMapper();
        $withoutThem = ['anonymous_name', 'home_group', 'personal_email', 'mobile_number', 'is_gsr', 'intergroup_position'];

        $missing = $mapper->validateMapping($withoutThem);

        expect($missing)->not->toContain('landline_number')
            ->not->toContain('preferred_contact')
            ->toBe([]);
    });

    it('exposes labels and aliases', function () {
        expect(MemberColumnMapper::getPropertyLabels()['anonymous_name'])->toBe('Anonymous Name')
            ->and(MemberColumnMapper::getAcceptedHeaders())->toHaveKey('member_id')
            ->and(MemberColumnMapper::getPropertyLabels()['landline_number'])->toBe('Landline')
            ->and(MemberColumnMapper::getPropertyLabels()['preferred_contact'])->toBe('Preferred Contact');
    });
});

// ─── GroupColumnMapper ──────────────────────────────────────────
describe('GroupColumnMapper', function () {
    it('maps contact and identity columns', function () {
        $mapper = new GroupColumnMapper();

        $mapping = $mapper->mapHeaders(['Group ID', 'Group Name', 'Group Email', 'Contact 1 Name']);

        expect($mapping[0])->toBe('group_id')
            ->and($mapping[1])->toBe('group_name')
            ->and($mapping[2])->toBe('email')
            ->and($mapping[3])->toBe('contact_1_name');
    });

    it('requires email and one identifier on validate', function () {
        $mapper = new GroupColumnMapper();

        // Neither identifier, no email.
        $missing = $mapper->validateMapping([0 => 'contact_1_name']);
        expect($missing)->toContain('email')
            ->toContain('group_id')
            ->toContain('group_name');

        // Email + one identifier satisfies the rule.
        expect($mapper->validateMapping([0 => 'email', 1 => 'group_id']))->toBe([]);
    });

    it('exposes labels and aliases', function () {
        expect(GroupColumnMapper::getPropertyLabels()['email'])->toBe('Group Email')
            ->and(GroupColumnMapper::getAcceptedHeaders())->toHaveKey('contact_3_phone');
    });
});

// ─── PositionColumnMapper ───────────────────────────────────────
describe('PositionColumnMapper', function () {
    it('maps its aliases', function () {
        $mapper = new PositionColumnMapper();

        $mapping = $mapper->mapHeaders(['Position Name', 'Sobriety', 'Term Length', 'Summary']);

        expect($mapping[0])->toBe('position_name')
            ->and($mapping[1])->toBe('minimum_sobriety')
            ->and($mapping[2])->toBe('term_years')
            ->and($mapping[3])->toBe('summary');
    });

    it('requires one identifier only on validate', function () {
        $mapper = new PositionColumnMapper();

        // No identifier at all.
        $missing = $mapper->validateMapping([0 => 'summary']);
        expect($missing)->toContain('position_id')
            ->toContain('position_name');

        // One identifier is enough (email is not required for positions).
        expect($mapper->validateMapping([0 => 'position_name']))->toBe([]);
    });

    it('exposes labels and aliases', function () {
        expect(PositionColumnMapper::getPropertyLabels()['minimum_sobriety'])->toBe('Minimum Sobriety')
            ->and(PositionColumnMapper::getAcceptedHeaders())->toHaveKey('term_years');
    });
});
