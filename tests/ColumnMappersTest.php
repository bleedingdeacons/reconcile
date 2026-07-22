<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reconcile\Group\GroupColumnMapper;
use Reconcile\Member\MemberColumnMapper;
use Reconcile\Position\PositionColumnMapper;

/**
 * Tests for the three spreadsheet column mappers.
 *
 * @covers \Reconcile\Member\MemberColumnMapper
 * @covers \Reconcile\Group\GroupColumnMapper
 * @covers \Reconcile\Position\PositionColumnMapper
 */
class ColumnMappersTest extends TestCase
{
    // ─── MemberColumnMapper ─────────────────────────────────────────

    /**
     * @test
     */
    public function member_maps_normalised_aliases_to_canonical_properties(): void
    {
        $mapper = new MemberColumnMapper();

        $mapping = $mapper->mapHeaders([
            'Anonymous Name', 'Home Group', 'Personal Email', 'Mobile',
            'GSR', 'Intergroup Position', 'Unknown Column', '12th Stepper',
        ]);

        $this->assertSame('anonymous_name', $mapping[0]);
        $this->assertSame('home_group', $mapping[1]);
        $this->assertSame('personal_email', $mapping[2]);
        $this->assertSame('mobile_number', $mapping[3]);
        $this->assertSame('is_gsr', $mapping[4]);
        $this->assertSame('intergroup_position', $mapping[5]);
        // Unknown headers are simply not mapped.
        $this->assertArrayNotHasKey(6, $mapping);
        $this->assertSame('is_twelfth_stepper', $mapping[7]);
    }

    /**
     * @test
     */
    public function member_validate_reports_missing_required_columns(): void
    {
        $mapper = new MemberColumnMapper();

        $missing = $mapper->validateMapping([0 => 'anonymous_name']);

        $this->assertContains('home_group', $missing);
        $this->assertContains('personal_email', $missing);
        $this->assertNotContains('anonymous_name', $missing);
    }

    /**
     * @test
     */
    public function member_validate_passes_when_all_required_present(): void
    {
        $mapper = new MemberColumnMapper();
        $full = ['anonymous_name', 'home_group', 'personal_email', 'mobile_number', 'is_gsr', 'intergroup_position'];

        $this->assertSame([], $mapper->validateMapping(array_values($full)));
    }

    /**
     * @test
     */
    public function member_exposes_labels_and_aliases(): void
    {
        $this->assertSame('Anonymous Name', MemberColumnMapper::getPropertyLabels()['anonymous_name']);
        $this->assertArrayHasKey('member_id', MemberColumnMapper::getAcceptedHeaders());
    }

    // ─── GroupColumnMapper ──────────────────────────────────────────

    /**
     * @test
     */
    public function group_maps_contact_and_identity_columns(): void
    {
        $mapper = new GroupColumnMapper();

        $mapping = $mapper->mapHeaders(['Group ID', 'Group Name', 'Group Email', 'Contact 1 Name']);

        $this->assertSame('group_id', $mapping[0]);
        $this->assertSame('group_name', $mapping[1]);
        $this->assertSame('email', $mapping[2]);
        $this->assertSame('contact_1_name', $mapping[3]);
    }

    /**
     * @test
     */
    public function group_validate_requires_email_and_one_identifier(): void
    {
        $mapper = new GroupColumnMapper();

        // Neither identifier, no email.
        $missing = $mapper->validateMapping([0 => 'contact_1_name']);
        $this->assertContains('email', $missing);
        $this->assertContains('group_id', $missing);
        $this->assertContains('group_name', $missing);

        // Email + one identifier satisfies the rule.
        $this->assertSame([], $mapper->validateMapping([0 => 'email', 1 => 'group_id']));
    }

    /**
     * @test
     */
    public function group_exposes_labels_and_aliases(): void
    {
        $this->assertSame('Group Email', GroupColumnMapper::getPropertyLabels()['email']);
        $this->assertArrayHasKey('contact_3_phone', GroupColumnMapper::getAcceptedHeaders());
    }

    // ─── PositionColumnMapper ───────────────────────────────────────

    /**
     * @test
     */
    public function position_maps_its_aliases(): void
    {
        $mapper = new PositionColumnMapper();

        $mapping = $mapper->mapHeaders(['Position Name', 'Sobriety', 'Term Length', 'Summary']);

        $this->assertSame('position_name', $mapping[0]);
        $this->assertSame('minimum_sobriety', $mapping[1]);
        $this->assertSame('term_years', $mapping[2]);
        $this->assertSame('summary', $mapping[3]);
    }

    /**
     * @test
     */
    public function position_validate_requires_one_identifier_only(): void
    {
        $mapper = new PositionColumnMapper();

        // No identifier at all.
        $missing = $mapper->validateMapping([0 => 'summary']);
        $this->assertContains('position_id', $missing);
        $this->assertContains('position_name', $missing);

        // One identifier is enough (email is not required for positions).
        $this->assertSame([], $mapper->validateMapping([0 => 'position_name']));
    }

    /**
     * @test
     */
    public function position_exposes_labels_and_aliases(): void
    {
        $this->assertSame('Minimum Sobriety', PositionColumnMapper::getPropertyLabels()['minimum_sobriety']);
        $this->assertArrayHasKey('term_years', PositionColumnMapper::getAcceptedHeaders());
    }
}
