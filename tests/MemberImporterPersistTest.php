<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Mockery;
use BleedingDeacons\WpMocks\TestCase;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Reconcile\Group\GroupLookup;
use Reconcile\Member\MemberImporter;
use Reconcile\Position\PositionLookup;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberFactory;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\PreferredContact;

/**
 * Exercises MemberImporter's real (non-dry-run) persist path: create, update
 * and save-failure. The dry-run and validation branches are covered by
 * MemberImporterTest.
 *
 * @covers \Reconcile\Member\MemberImporter
 */
class MemberImporterPersistTest extends TestCase
{
    /** @var MemberRepository&Mockery\MockInterface */
    private $memberRepo;
    /** @var MemberFactory&Mockery\MockInterface */
    private $memberFactory;
    /** @var GroupLookup&Mockery\MockInterface */
    private $groupLookup;
    /** @var PositionLookup&Mockery\MockInterface */
    private $positionLookup;

    private MemberImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $configuration = Mockery::mock(Configuration::class);
        $configuration->shouldReceive('getConfig')->andReturn([
            'POST_TYPE' => 'intergroup-member',
            'FIELD_ANONYMOUS_NAME' => 'anonymous-name',
            'FIELD_PERSONAL_EMAIL' => 'personal-email',
            'FIELD_MOBILE_NUMBER' => 'mobile-number',
        ])->byDefault();

        $this->memberRepo = Mockery::mock(MemberRepository::class);
        $this->memberFactory = Mockery::mock(MemberFactory::class);
        $this->groupLookup = Mockery::mock(GroupLookup::class);
        $this->positionLookup = Mockery::mock(PositionLookup::class);

        $this->groupLookup->shouldReceive('resetUnresolved')->byDefault();
        $this->positionLookup->shouldReceive('resetUnresolved')->byDefault();
        $this->groupLookup->shouldReceive('getUnresolvedNames')->andReturn([])->byDefault();
        $this->positionLookup->shouldReceive('getUnresolvedNames')->andReturn([])->byDefault();
        $this->groupLookup->shouldReceive('resolve')->andReturn(0)->byDefault();
        $this->positionLookup->shouldReceive('resolve')->andReturn(0)->byDefault();

        $this->importer = new MemberImporter(
            $configuration,
            $this->memberRepo,
            $this->memberFactory,
            $this->groupLookup,
            $this->positionLookup
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private const HEADERS = [
        'Member ID', 'Anonymous Name', 'Home Group', 'Personal Email',
        'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation',
    ];

    private const CONTACT_HEADERS = [
        'Member ID', 'Anonymous Name', 'Home Group', 'Personal Email',
        'Mobile', 'Landline', 'Preferred Contact', 'GSR',
        'Intergroup Position', 'Intergroup Position Rotation',
    ];

    private const FULL_HEADERS = [
        'Member ID', 'Anonymous Name', 'Home Group', 'Personal Email',
        'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation',
        '12th Stepper', 'Area', 'Accepts',
    ];

    /**
     * @param array<int, array<int, string>> $rows
     * @param string[] $headers
     */
    private function writeCsv(array $rows, array $headers = self::HEADERS): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mem_import_') . '.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);
        return $path;
    }

    /** @return Member&Mockery\MockInterface */
    private function member()
    {
        return Mockery::mock(Member::class)->shouldIgnoreMissing();
    }

    /**
     * @test
     */
    public function creates_a_new_member_when_the_name_is_unknown(): void
    {
        // No Member ID, name not found → create path.
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberFactory->shouldReceive('createNew')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $result = $this->importer->import($this->writeCsv([
            ['', 'New Member', '', 'new@example.com', '555', 'no', '', ''],
        ]));

        $this->assertSame(1, $result->getCreated());
        $this->assertSame(0, $result->getSkipped());
    }

    /**
     * @test
     */
    public function creates_a_member_with_twelfth_stepper_area_and_accepts(): void
    {
        // Exercises the optional-column parsing (12th-stepper flag, area text,
        // and the pipe-separated accepts list).
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberFactory->shouldReceive('createNew')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $result = $this->importer->import($this->writeCsv([
            ['', 'Stepper', '', 's@example.com', '555', 'no', '', '', 'yes', 'North London', 'male|female|all'],
        ], self::FULL_HEADERS));

        $this->assertSame(1, $result->getCreated());
    }

    // ─── landline and preferred contact ─────────────────────────────

    /**
     * @test
     */
    public function a_landline_and_preference_reach_the_factory(): void
    {
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $captured = [];
        $this->memberFactory->shouldReceive('createNew')->andReturnUsing(
            function (...$args) use (&$captured) {
                $captured = $args;
                return $this->member();
            }
        );

        $result = $this->importer->import($this->writeCsv([
            ['', 'New Member', '', 'new@example.com', '555', '0117 496 0000', 'Landline', 'no', '', ''],
        ], self::CONTACT_HEADERS));

        $this->assertSame(1, $result->getCreated());
        $this->assertContains('0117 496 0000', $captured);
        $this->assertContains(PreferredContact::Landline, $captured);
    }

    /**
     * A typo is not an instruction. Anything the column does not recognise
     * falls back to Mobile on a create, rather than guessing.
     *
     * @test
     */
    public function an_unrecognised_preference_falls_back_to_mobile(): void
    {
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $captured = [];
        $this->memberFactory->shouldReceive('createNew')->andReturnUsing(
            function (...$args) use (&$captured) {
                $captured = $args;
                return $this->member();
            }
        );

        $this->importer->import($this->writeCsv([
            ['', 'New Member', '', 'new@example.com', '555', '0117 496 0000', 'Home Phone', 'no', '', ''],
        ], self::CONTACT_HEADERS));

        $this->assertContains(PreferredContact::Mobile, $captured);
        $this->assertNotContains(PreferredContact::Landline, $captured);
    }

    /**
     * Matching is case-insensitive: "landline" is plainly asking for the
     * same thing as "Landline".
     *
     * @test
     */
    public function the_preference_column_is_case_insensitive(): void
    {
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $captured = [];
        $this->memberFactory->shouldReceive('createNew')->andReturnUsing(
            function (...$args) use (&$captured) {
                $captured = $args;
                return $this->member();
            }
        );

        $this->importer->import($this->writeCsv([
            ['', 'New Member', '', 'new@example.com', '555', '0117 496 0000', ' landline ', 'no', '', ''],
        ], self::CONTACT_HEADERS));

        $this->assertContains(PreferredContact::Landline, $captured);
    }

    /**
     * A spreadsheet written before these columns existed must not erase
     * either field. On the create path that means the type defaults; the
     * update path is covered in MemberImporterTest, where a revisor is
     * wired and null means "carry over".
     *
     * @test
     */
    public function a_spreadsheet_without_the_columns_creates_with_the_defaults(): void
    {
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $captured = [];
        $this->memberFactory->shouldReceive('createNew')->andReturnUsing(
            function (...$args) use (&$captured) {
                $captured = $args;
                return $this->member();
            }
        );

        $this->importer->import($this->writeCsv([
            ['', 'New Member', '', 'new@example.com', '555', 'no', '', ''],
        ]));

        $this->assertContains(PreferredContact::Mobile, $captured);
        $this->assertNotContains(PreferredContact::Landline, $captured);
    }

    /**
     * @test
     */
    public function updates_a_member_found_by_id(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(42)->andReturn($this->member());
        $this->memberFactory->shouldReceive('createNew')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $result = $this->importer->import($this->writeCsv([
            ['42', 'Existing', '', 'exists@example.com', '555', 'no', '', ''],
        ]));

        $this->assertSame(1, $result->getUpdated());
    }

    /**
     * @test
     */
    public function a_member_id_that_does_not_exist_is_skipped(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(99)->andReturn(null);

        $result = $this->importer->import($this->writeCsv([
            ['99', 'Ghost', '', 'ghost@example.com', '555', 'no', '', ''],
        ]));

        $this->assertSame(1, $result->getSkipped());
    }

    /**
     * @test
     */
    public function a_non_numeric_member_id_is_skipped(): void
    {
        $result = $this->importer->import($this->writeCsv([
            ['abc', 'Bad Id', '', 'bad@example.com', '555', 'no', '', ''],
        ]));

        $this->assertSame(1, $result->getSkipped());
    }

    /**
     * @test
     */
    public function creates_a_member_with_a_resolved_position_and_rotation(): void
    {
        // A position name that resolves plus a valid rotation exercises the
        // position-resolution and rotation-parsing branches.
        $this->positionLookup->shouldReceive('resolve')->with('Chair')->andReturn(7);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberFactory->shouldReceive('createNew')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $result = $this->importer->import($this->writeCsv([
            ['', 'Officer', 'Group One', 'o@example.com', '555', 'yes', 'Chair', '2026-01-01'],
        ]));

        $this->assertSame(1, $result->getCreated());
    }

    /**
     * @test
     */
    public function a_position_without_a_rotation_is_skipped(): void
    {
        // A resolved position with no rotation date is a validation failure.
        $this->positionLookup->shouldReceive('resolve')->with('Chair')->andReturn(7);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberRepo->shouldNotReceive('save');

        $result = $this->importer->import($this->writeCsv([
            ['', 'Officer', '', 'o@example.com', '555', 'no', 'Chair', ''],
        ]));

        $this->assertSame(1, $result->getSkipped());
        $this->assertSame(0, $result->getCreated());
    }

    /**
     * @test
     */
    public function a_save_failure_on_create_is_skipped(): void
    {
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberFactory->shouldReceive('createNew')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->once()->andReturn(false);

        $result = $this->importer->import($this->writeCsv([
            ['', 'New Member', '', 'new@example.com', '555', 'no', '', ''],
        ]));

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    /**
     * @test
     */
    public function a_failed_post_insert_on_create_is_skipped(): void
    {
        // wp_insert_post returns 0 → the member post could not be created.
        Functions\when('wp_insert_post')->justReturn(0);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($this->writeCsv([
            ['', 'New Member', '', 'new@example.com', '555', 'no', '', ''],
        ]));

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }
}
