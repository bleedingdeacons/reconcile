<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit\Import;

use Group\GroupLookup;
use Member\MemberImporter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Position\PositionLookup;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberFactory;
use Unity\Members\Interfaces\MemberRepository;

/**
 * Unit tests for MemberImporter
 */
class MemberImporterTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private Configuration|Mockery\MockInterface $configuration;
    private MemberRepository|Mockery\MockInterface $memberRepo;
    private MemberFactory|Mockery\MockInterface $memberFactory;
    private GroupLookup|Mockery\MockInterface $groupLookup;
    private PositionLookup|Mockery\MockInterface $positionLookup;
    private MemberImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuration = Mockery::mock(Configuration::class);
        $this->configuration->shouldReceive('getConfig')
            ->with(Member::class)
            ->andReturn([
                'POST_TYPE' => 'intergroup-member',
                'FIELD_ANONYMOUS_NAME' => 'about-layout-group_anonymous-name',
                'FIELD_PERSONAL_EMAIL' => 'about-layout-group_personal-email',
                'FIELD_MOBILE_NUMBER' => 'about-layout-group_mobile-number',
            ])
            ->byDefault();

        $this->memberRepo = Mockery::mock(MemberRepository::class);
        $this->memberFactory = Mockery::mock(MemberFactory::class);
        $this->groupLookup = Mockery::mock(GroupLookup::class);
        $this->positionLookup = Mockery::mock(PositionLookup::class);

        $this->groupLookup->shouldReceive('resetUnresolved')->byDefault();
        $this->positionLookup->shouldReceive('resetUnresolved')->byDefault();
        $this->groupLookup->shouldReceive('getUnresolvedNames')->andReturn([])->byDefault();
        $this->positionLookup->shouldReceive('getUnresolvedNames')->andReturn([])->byDefault();

        $this->importer = new MemberImporter(
            $this->configuration,
            $this->memberRepo,
            $this->memberFactory,
            $this->groupLookup,
            $this->positionLookup
        );
    }

    /**
     * Helper: write a temporary CSV and return its path.
     */
    private function writeCsv(array $headers, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import_test_') . '.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }

    // ── Null dependency handling ────────────────────────────────────────

    /**
     * @test
     */
    public function import_returns_error_when_member_repository_is_null(): void
    {
        $importer = new MemberImporter(
            $this->configuration,
            null,
            $this->memberFactory,
            $this->groupLookup,
            $this->positionLookup
        );

        $result = $importer->import('/tmp/dummy.csv');

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('MemberRepository', $result->getErrors()[0]);
    }

    /**
     * @test
     */
    public function import_returns_error_when_member_factory_is_null(): void
    {
        $importer = new MemberImporter(
            $this->configuration,
            $this->memberRepo,
            null,
            $this->groupLookup,
            $this->positionLookup
        );

        $result = $importer->import('/tmp/dummy.csv');

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('MemberFactory', $result->getErrors()[0]);
    }

    // ── Missing / invalid columns ──────────────────────────────────────

    /**
     * @test
     */
    public function import_returns_error_when_required_columns_missing(): void
    {
        $path = $this->writeCsv(['Anonymous Name', 'Random Column'], [
            ['John D.', 'foo'],
        ]);

        $result = $this->importer->import($path);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('Missing required columns', $result->getErrors()[0]);

        unlink($path);
    }

    // ── Dry run ────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function dry_run_counts_without_persisting(): void
    {
        $path = $this->writeCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'yes', '', ''],
                ['Bob B.', 'Group Two', 'bob@example.com', '555-0002', 'no', '', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->with('Group One')->andReturn(10);
        $this->groupLookup->shouldReceive('resolve')->with('Group Two')->andReturn(20);
        $this->positionLookup->shouldReceive('resolve')->with('')->andReturn(0);

        // No existing members
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        // Repository and factory should NOT be called for persistence
        $this->memberRepo->shouldNotReceive('save');
        $this->memberFactory->shouldNotReceive('createNew');

        $result = $this->importer->import($path, dryRun: true);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(2, $result->getTotalRows());
        $this->assertEquals(2, $result->getCreated());
        $this->assertEquals(0, $result->getUpdated());
        $this->assertEquals(0, $result->getSkipped());

        unlink($path);
    }

    // ── Row skipping ───────────────────────────────────────────────────

    /**
     * @test
     */
    public function import_skips_row_with_empty_anonymous_name(): void
    {
        $path = $this->writeCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['', 'Group One', 'test@example.com', '555-0001', 'no', '', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(0);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);

        $result = $this->importer->import($path, dryRun: true);

        $this->assertEquals(1, $result->getSkipped());
        $skippedRows = $result->getSkippedRows();
        $this->assertCount(1, $skippedRows);
        $this->assertStringContainsString('Anonymous Name is empty', $skippedRows[0]['reason']);

        unlink($path);
    }

    /**
     * @test
     */
    public function import_skips_row_with_position_but_no_rotation(): void
    {
        $path = $this->writeCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', 'Secretary', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->with('Secretary')->andReturn(100);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        $this->assertEquals(1, $result->getSkipped());
        $this->assertStringContainsString('Rotation is empty', $result->getSkippedRows()[0]['reason']);

        unlink($path);
    }

    /**
     * @test
     */
    public function import_skips_row_with_invalid_date_format(): void
    {
        $path = $this->writeCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', 'Secretary', 'not-a-date'],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->with('Secretary')->andReturn(100);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        $this->assertEquals(1, $result->getSkipped());
        $this->assertStringContainsString('not a recognised date format', $result->getSkippedRows()[0]['reason']);

        unlink($path);
    }

    // ── Date parsing ───────────────────────────────────────────────────

    /**
     * @test
     * @dataProvider validDateProvider
     */
    public function import_accepts_valid_date_formats(string $input): void
    {
        $path = $this->writeCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', 'Secretary', $input],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(100);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        $this->assertEquals(0, $result->getSkipped(), "Date '{$input}' should be accepted but was skipped");
        $this->assertEquals(1, $result->getCreated());

        unlink($path);
    }

    public static function validDateProvider(): array
    {
        return [
            'yyyy/MM/dd' => ['2025/06/15'],
            'yyyy-MM-dd' => ['2025-06-15'],
            'yyyy.MM.dd' => ['2025.06.15'],
            'dd/MM/yyyy' => ['15/06/2025'],
            'dd-MM-yyyy' => ['15-06-2025'],
            'dd/MM/yy'   => ['15/06/25'],
        ];
    }

    // ── GSR parsing ────────────────────────────────────────────────────

    /**
     * @test
     * @dataProvider gsrTruthyProvider
     */
    public function import_parses_gsr_truthy_values(string $input): void
    {
        // Verify the static method recognises these values
        $truthyValues = MemberImporter::getTruthyValues();
        $this->assertContains(strtolower(trim($input)), $truthyValues);
    }

    public static function gsrTruthyProvider(): array
    {
        return [
            'yes'   => ['yes'],
            'Yes'   => ['Yes'],
            'y'     => ['y'],
            'true'  => ['true'],
            '1'     => ['1'],
        ];
    }

    // ── Unresolved group/position warnings ─────────────────────────────

    /**
     * @test
     */
    public function import_warns_on_unresolved_group_names(): void
    {
        $path = $this->writeCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['Alice A.', 'Unknown Group', 'alice@example.com', '555-0001', 'no', '', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->with('Unknown Group')->andReturn(0);
        $this->positionLookup->shouldReceive('resolve')->with('')->andReturn(0);
        $this->groupLookup->shouldReceive('getUnresolvedNames')->andReturn(['Unknown Group']);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        $this->assertTrue($result->hasWarnings());
        $warnings = implode(' ', $result->getWarnings());
        $this->assertStringContainsString('Unknown Group', $warnings);

        unlink($path);
    }

    /**
     * @test
     */
    public function import_warns_on_unresolved_position_names(): void
    {
        $path = $this->writeCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', 'Fake Position', '2025/01/01'],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->with('Fake Position')->andReturn(0);
        $this->positionLookup->shouldReceive('getUnresolvedNames')->andReturn(['Fake Position']);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        $this->assertTrue($result->hasWarnings());
        $warnings = implode(' ', $result->getWarnings());
        $this->assertStringContainsString('Fake Position', $warnings);

        unlink($path);
    }

    // ── Create vs update ───────────────────────────────────────────────

    /**
     * @test
     */
    public function dry_run_detects_existing_members_as_updates(): void
    {
        $path = $this->writeCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'yes', '', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);

        // Simulate an existing member found by anonymous name
        $existingMember = Mockery::mock(Member::class);
        $existingMember->shouldReceive('getId')->andReturn(42);
        $existingMember->shouldReceive('showAnonymousName')->andReturn(false);
        $existingMember->shouldReceive('showMemberProfile')->andReturn(false);
        $existingMember->shouldReceive('getAnonymousProfile')->andReturn('');
        $existingMember->shouldReceive('getIntergroupPositionRotation')->andReturn('');
        $existingMember->shouldReceive('getMeetingPO')->andReturn(null);

        $this->memberRepo->shouldReceive('findAll')->andReturn([$existingMember]);

        $result = $this->importer->import($path, dryRun: true);

        $this->assertEquals(0, $result->getCreated());
        $this->assertEquals(1, $result->getUpdated());

        unlink($path);
    }

    // ── Accepted date format labels ────────────────────────────────────

    /**
     * @test
     */
    public function getAcceptedDateFormats_returns_non_empty_array(): void
    {
        $formats = MemberImporter::getAcceptedDateFormats();

        $this->assertNotEmpty($formats);
        $this->assertContains('yyyy/MM/dd', $formats);
        $this->assertContains('dd/MM/yyyy', $formats);
    }

    // ── File read errors ───────────────────────────────────────────────

    /**
     * @test
     */
    public function import_returns_error_for_nonexistent_file(): void
    {
        $result = $this->importer->import('/tmp/nonexistent_file_abc123.csv');

        $this->assertTrue($result->hasErrors());
    }

    // ── Member ID lookup ──────────────────────────────────────────────

    /**
     * @test
     */
    public function import_uses_member_id_to_find_existing_member(): void
    {
        $path = $this->writeCsv(
            ['Member ID', 'Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['42', 'Alice A.', 'Group One', 'alice@example.com', '555-0001', 'yes', '', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);

        // Simulate an existing member found by ID
        $existingMember = Mockery::mock(Member::class);
        $existingMember->shouldReceive('getId')->andReturn(42);
        $existingMember->shouldReceive('showAnonymousName')->andReturn(false);
        $existingMember->shouldReceive('showMemberProfile')->andReturn(false);
        $existingMember->shouldReceive('getAnonymousProfile')->andReturn('');
        $existingMember->shouldReceive('getIntergroupPositionRotation')->andReturn('');
        $existingMember->shouldReceive('getMeetingPO')->andReturn(null);

        $this->memberRepo->shouldReceive('findById')->with(42)->andReturn($existingMember);

        // findAll should NOT be called for member lookup when ID is provided
        $this->memberRepo->shouldNotReceive('findAll');

        $result = $this->importer->import($path, dryRun: true);

        $this->assertEquals(0, $result->getCreated());
        $this->assertEquals(1, $result->getUpdated());
        $this->assertEquals(0, $result->getSkipped());

        unlink($path);
    }

    /**
     * @test
     */
    public function import_skips_row_with_non_numeric_member_id(): void
    {
        $path = $this->writeCsv(
            ['Member ID', 'Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['abc', 'Alice A.', 'Group One', 'alice@example.com', '555-0001', 'yes', '', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);

        $result = $this->importer->import($path, dryRun: true);

        $this->assertEquals(1, $result->getSkipped());
        $this->assertStringContainsString('not a valid numeric ID', $result->getSkippedRows()[0]['reason']);

        unlink($path);
    }

    /**
     * @test
     */
    public function import_skips_row_when_member_id_does_not_match(): void
    {
        $path = $this->writeCsv(
            ['Member ID', 'Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['999', 'Alice A.', 'Group One', 'alice@example.com', '555-0001', 'yes', '', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);

        $this->memberRepo->shouldReceive('findById')->with(999)->andReturn(null);

        $result = $this->importer->import($path, dryRun: true);

        $this->assertEquals(1, $result->getSkipped());
        $this->assertStringContainsString('does not match an existing member', $result->getSkippedRows()[0]['reason']);

        unlink($path);
    }

    /**
     * @test
     */
    public function import_falls_back_to_anonymous_name_when_member_id_empty(): void
    {
        $path = $this->writeCsv(
            ['Member ID', 'Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['', 'Alice A.', 'Group One', 'alice@example.com', '555-0001', 'yes', '', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);

        // No existing member — should count as a create
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        // findById should NOT be called when member_id is empty
        $this->memberRepo->shouldNotReceive('findById');

        $result = $this->importer->import($path, dryRun: true);

        $this->assertEquals(1, $result->getCreated());
        $this->assertEquals(0, $result->getUpdated());

        unlink($path);
    }

    // ── 12th Stepper / Area / Accepts ──────────────────────────────────

    /**
     * @test
     */
    public function import_works_when_new_optional_columns_are_absent(): void
    {
        // The existing column set must keep working unchanged — the three new
        // columns are optional and absent spreadsheets must not regress.
        $path = $this->writeCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', '', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->with('')->andReturn(0);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(1, $result->getCreated());
        $this->assertEquals(0, $result->getSkipped());
        $this->assertEmpty($result->getWarnings());

        unlink($path);
    }

    /**
     * @test
     */
    public function import_parses_twelfth_stepper_with_area_and_accepts(): void
    {
        $path = $this->writeCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation', '12th Stepper', 'Area', 'Accepts'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', '', '', 'yes', 'East London', 'Male|Female'],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        // Capture the named args that reach the factory. MemberImporter
        // calls createNew with named arguments, so reflect on the callee
        // signature to map positional args back to names.
        $capturedNamed = null;
        $this->memberFactory->shouldReceive('createNew')
            ->andReturnUsing(function (...$args) use (&$capturedNamed) {
                $capturedNamed = $args;
                return Mockery::mock(Member::class);
            });

        $result = $this->importer->import($path, dryRun: true);

        $this->assertEquals(1, $result->getCreated());
        $this->assertEquals(0, $result->getSkipped());

        // PHP collapses named args into the variadic in positional order,
        // matching the interface signature: the trailing three values must
        // be the parsed 12th-stepper bool, area string, and accepts array.
        $this->assertNotNull($capturedNamed, 'createNew should have been called.');
        $this->assertContains(true, $capturedNamed, 'twelfthStepper=true should reach the factory.');
        $this->assertContains('East London', $capturedNamed, 'Area string should reach the factory.');

        // Find the accepts array among the captured args.
        $acceptsArg = null;
        foreach ($capturedNamed as $arg) {
            if (is_array($arg) && in_array('accepts-male', $arg, true)) {
                $acceptsArg = $arg;
                break;
            }
        }
        $this->assertNotNull($acceptsArg, 'Accepts array should reach the factory.');
        $this->assertSame(['accepts-male', 'accepts-female'], $acceptsArg);

        unlink($path);
    }

    /**
     * @test
     */
    public function import_clears_area_and_accepts_with_warning_when_not_twelfth_stepper(): void
    {
        $path = $this->writeCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation', '12th Stepper', 'Area', 'Accepts'],
            [
                // 12th Stepper is "no" but Area and Accepts are populated —
                // ACF makes these fields conditional on the 12th-stepper flag,
                // so the importer clears them and warns the operator.
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', '', '', 'no', 'East London', 'Male'],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        $this->assertEquals(1, $result->getCreated());
        $this->assertEquals(0, $result->getSkipped());

        $warnings = $result->getWarnings();
        $this->assertNotEmpty($warnings, 'Expected a clearing warning to be raised.');
        $combined = implode("\n", $warnings);
        $this->assertStringContainsString('12th Stepper is not set', $combined);
        $this->assertStringContainsString('Area', $combined);
        $this->assertStringContainsString('Accepts', $combined);

        unlink($path);
    }

    /**
     * @test
     */
    public function import_does_not_warn_when_not_twelfth_stepper_and_area_accepts_are_empty(): void
    {
        $path = $this->writeCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation', '12th Stepper', 'Area', 'Accepts'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', '', '', 'no', '', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        $this->assertEquals(1, $result->getCreated());
        $this->assertEmpty(
            $result->getWarnings(),
            'Did not expect a clearing warning when Area and Accepts are already empty.'
        );

        unlink($path);
    }

    /**
     * @test
     */
    public function import_skips_row_with_unrecognised_accepts_value(): void
    {
        $path = $this->writeCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation', '12th Stepper', 'Area', 'Accepts'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', '', '', 'yes', 'East London', 'Male|Banana'],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        $this->assertEquals(1, $result->getSkipped());
        $skipped = $result->getSkippedRows();
        $this->assertStringContainsString('Banana', $skipped[0]['reason']);
        $this->assertStringContainsString('Male, Female, Non-Binary, All', $skipped[0]['reason']);

        unlink($path);
    }

    /**
     * @test
     */
    public function import_accepts_labels_case_insensitively(): void
    {
        $path = $this->writeCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation', '12th Stepper', 'Area', 'Accepts'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', '', '', 'yes', 'East London', ' male | NON-BINARY |all '],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        $this->assertEquals(1, $result->getCreated());
        $this->assertEquals(0, $result->getSkipped());
        $this->assertEmpty($result->getWarnings());

        unlink($path);
    }
}