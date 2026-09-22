<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit\Import;

use Mockery\MockInterface;
use Reconcile\Group\GroupLookup;
use Reconcile\Member\MemberImporter;
use Mockery;
use Reconcile\Position\PositionLookup;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberFactory;
use Unity\Members\Interfaces\MemberRepository;

/*
 * Unit tests for MemberImporter
 */

/**
 * Helper: write a temporary CSV and return its path.
 */
function memberImportCsv(array $headers, array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'import_test_') . '.csv';
    $handle = fopen($path, 'w');
    fputcsv($handle, $headers, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }
    fclose($handle);

    return $path;
}

/**
 * A minimal existing-member stub for update-path tests.
 *
 * The importer's create-path createNew() does not read the existing
 * member (the revisor does, but these Mockery-only tests run without a
 * revisor), so the stub only needs the id the importer reads while
 * routing and reporting the update.
 */
function existingImportedMember(int $id = 1): Member
{
    $member = Mockery::mock(Member::class);
    $member->shouldReceive('getId')->andReturn($id);
    return $member;
}

dataset('valid rotation dates', [
    'yyyy/MM/dd' => ['2025/06/15'],
    'yyyy-MM-dd' => ['2025-06-15'],
    'yyyy.MM.dd' => ['2025.06.15'],
    'dd/MM/yyyy' => ['15/06/2025'],
    'dd-MM-yyyy' => ['15-06-2025'],
    'dd/MM/yy'   => ['15/06/25'],
]);

dataset('GSR truthy values', [
    'yes'   => ['yes'],
    'Yes'   => ['Yes'],
    'y'     => ['y'],
    'true'  => ['true'],
    '1'     => ['1'],
]);

beforeEach(function () {
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
});

// ── Null dependency handling ────────────────────────────────────────
describe('Null dependency handling', function () {
    it('returns an error when the member repository is null', function () {
        $importer = new MemberImporter(
            $this->configuration,
            null,
            $this->memberFactory,
            $this->groupLookup,
            $this->positionLookup
        );

        $result = $importer->import('/tmp/dummy.csv');

        expect($result->hasErrors())->toBeTrue()
            ->and($result->getErrors()[0])->toContain('MemberRepository');
    });

    it('returns an error when the member factory is null', function () {
        $importer = new MemberImporter(
            $this->configuration,
            $this->memberRepo,
            null,
            $this->groupLookup,
            $this->positionLookup
        );

        $result = $importer->import('/tmp/dummy.csv');

        expect($result->hasErrors())->toBeTrue()
            ->and($result->getErrors()[0])->toContain('MemberFactory');
    });
});

// ── Missing / invalid columns ──────────────────────────────────────
describe('Missing / invalid columns', function () {
    it('returns an error when required columns are missing', function () {
        $path = memberImportCsv(['Anonymous Name', 'Random Column'], [
            ['John D.', 'foo'],
        ]);

        $result = $this->importer->import($path);

        expect($result->hasErrors())->toBeTrue()
            ->and($result->getErrors()[0])->toContain('Missing required columns');

        unlink($path);
    });
});

// ── Dry run ────────────────────────────────────────────────────────
describe('Dry run', function () {
    it('counts without persisting', function () {
        $path = memberImportCsv(
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

        expect($result->isSuccess())->toBeTrue()
            ->and($result->getTotalRows())->toEqual(2)
            ->and($result->getCreated())->toEqual(2)
            ->and($result->getUpdated())->toEqual(0)
            ->and($result->getSkipped())->toEqual(0);

        unlink($path);
    });
});

// ── Row skipping ───────────────────────────────────────────────────
describe('Row skipping', function () {
    it('skips a row with an empty anonymous name', function () {
        $path = memberImportCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['', 'Group One', 'test@example.com', '555-0001', 'no', '', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(0);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);

        $result = $this->importer->import($path, dryRun: true);

        expect($result->getSkipped())->toEqual(1);
        $skippedRows = $result->getSkippedRows();
        expect($skippedRows)->toHaveCount(1)
            ->and($skippedRows[0]['reason'])->toContain('Anonymous Name is empty');

        unlink($path);
    });

    it('skips a row with a position but no rotation', function () {
        $path = memberImportCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', 'Secretary', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->with('Secretary')->andReturn(100);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        expect($result->getSkipped())->toEqual(1)
            ->and($result->getSkippedRows()[0]['reason'])->toContain('Rotation is empty');

        unlink($path);
    });

    it('skips a row with an invalid date format', function () {
        $path = memberImportCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', 'Secretary', 'not-a-date'],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->with('Secretary')->andReturn(100);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        expect($result->getSkipped())->toEqual(1)
            ->and($result->getSkippedRows()[0]['reason'])->toContain('not a recognised date format');

        unlink($path);
    });
});

// ── Date parsing ───────────────────────────────────────────────────
describe('Date parsing', function () {
    it('accepts valid date formats', function (string $input) {
        $path = memberImportCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', 'Secretary', $input],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(100);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        expect($result->getSkipped())->toEqual(0, "Date '{$input}' should be accepted but was skipped")
            ->and($result->getCreated())->toEqual(1);

        unlink($path);
    })->with('valid rotation dates');
});

// ── GSR parsing ────────────────────────────────────────────────────
describe('GSR parsing', function () {
    it('parses GSR truthy values', function (string $input) {
        // Verify the static method recognises these values
        $truthyValues = MemberImporter::getTruthyValues();
        expect($truthyValues)->toContain(strtolower(trim($input)));
    })->with('GSR truthy values');
});

// ── Unresolved group/position warnings ─────────────────────────────
describe('Unresolved group/position warnings', function () {
    it('warns on unresolved group names', function () {
        $path = memberImportCsv(
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

        expect($result->hasWarnings())->toBeTrue();
        $warnings = implode(' ', $result->getWarnings());
        expect($warnings)->toContain('Unknown Group');

        unlink($path);
    });

    it('warns on unresolved position names', function () {
        $path = memberImportCsv(
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

        expect($result->hasWarnings())->toBeTrue();
        $warnings = implode(' ', $result->getWarnings());
        expect($warnings)->toContain('Fake Position');

        unlink($path);
    });
});

// ── Create vs update ───────────────────────────────────────────────
describe('Create vs update', function () {
    it('detects existing members as updates on a dry run', function () {
        $path = memberImportCsv(
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

        expect($result->getCreated())->toEqual(0)
            ->and($result->getUpdated())->toEqual(1);

        unlink($path);
    });
});

// ── Accepted date format labels ────────────────────────────────────
describe('Accepted date format labels', function () {
    it('returns a non-empty array from getAcceptedDateFormats()', function () {
        $formats = MemberImporter::getAcceptedDateFormats();

        expect($formats)->not->toBeEmpty()
            ->toContain('yyyy/MM/dd')
            ->toContain('dd/MM/yyyy');
    });
});

// ── File read errors ───────────────────────────────────────────────
describe('File read errors', function () {
    it('returns an error for a nonexistent file', function () {
        $result = $this->importer->import('/tmp/nonexistent_file_abc123.csv');

        expect($result->hasErrors())->toBeTrue();
    });
});

// ── Member ID lookup ──────────────────────────────────────────────
describe('Member ID lookup', function () {
    it('uses the member ID to find an existing member', function () {
        $path = memberImportCsv(
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

        expect($result->getCreated())->toEqual(0)
            ->and($result->getUpdated())->toEqual(1)
            ->and($result->getSkipped())->toEqual(0);

        unlink($path);
    });

    it('skips a row with a non-numeric member ID', function () {
        $path = memberImportCsv(
            ['Member ID', 'Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['abc', 'Alice A.', 'Group One', 'alice@example.com', '555-0001', 'yes', '', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);

        $result = $this->importer->import($path, dryRun: true);

        expect($result->getSkipped())->toEqual(1)
            ->and($result->getSkippedRows()[0]['reason'])->toContain('not a valid numeric ID');

        unlink($path);
    });

    it('skips a row when the member ID does not match', function () {
        $path = memberImportCsv(
            ['Member ID', 'Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['999', 'Alice A.', 'Group One', 'alice@example.com', '555-0001', 'yes', '', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);

        $this->memberRepo->shouldReceive('findById')->with(999)->andReturn(null);

        $result = $this->importer->import($path, dryRun: true);

        expect($result->getSkipped())->toEqual(1)
            ->and($result->getSkippedRows()[0]['reason'])->toContain('does not match an existing member');

        unlink($path);
    });

    it('falls back to the anonymous name when the member ID is empty', function () {
        $path = memberImportCsv(
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

        expect($result->getCreated())->toEqual(1)
            ->and($result->getUpdated())->toEqual(0);

        unlink($path);
    });
});

// ── 12th Stepper / Area / Accepts ──────────────────────────────────
describe('12th Stepper / Area / Accepts', function () {
    it('works when the new optional columns are absent', function () {
        // The existing column set must keep working unchanged — the three new
        // columns are optional and absent spreadsheets must not regress.
        $path = memberImportCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', '', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->with('')->andReturn(0);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        expect($result->isSuccess())->toBeTrue()
            ->and($result->getCreated())->toEqual(1)
            ->and($result->getSkipped())->toEqual(0)
            ->and($result->getWarnings())->toBeEmpty();

        unlink($path);
    });

    it('parses 12th stepper with area and accepts', function () {
        $path = memberImportCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation', '12th Stepper', 'Area', 'Accepts'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', '', '', 'yes', 'East London', 'Male|Female'],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);

        // Capture the args reaching the factory via a real update: an existing
        // member is found by name and saved (no dry run — a dry run reports
        // counts without building), so createNew is exercised on a persisting
        // path. The parsed 12th-stepper/area/accepts values are computed the
        // same way whether the member is created or updated.
        $this->memberRepo->shouldReceive('findAll')->andReturn([existingImportedMember()]);
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $capturedNamed = null;
        $this->memberFactory->shouldReceive('createNew')
            ->andReturnUsing(function (...$args) use (&$capturedNamed) {
                $capturedNamed = $args;
                return Mockery::mock(Member::class);
            });

        $result = $this->importer->import($path);

        expect($result->getUpdated())->toEqual(1)
            ->and($result->getSkipped())->toEqual(0);

        // PHP collapses named args into the variadic in positional order,
        // matching the interface signature: the trailing three values must
        // be the parsed 12th-stepper bool, area string, and accepts array.
        expect($capturedNamed)->not->toBeNull('createNew should have been called.')
            // twelfthStepper=true should reach the factory.
            ->toContain(true)
            // Area string should reach the factory.
            ->toContain('East London');

        // Find the accepts array among the captured args.
        $acceptsArg = null;
        foreach ($capturedNamed as $arg) {
            if (is_array($arg) && in_array('accepts-male', $arg, true)) {
                $acceptsArg = $arg;
                break;
            }
        }
        expect($acceptsArg)->not->toBeNull('Accepts array should reach the factory.')
            ->toBe(['accepts-male', 'accepts-female']);

        unlink($path);
    });

    it('clears area and accepts with a warning when not a 12th stepper', function () {
        $path = memberImportCsv(
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

        expect($result->getCreated())->toEqual(1)
            ->and($result->getSkipped())->toEqual(0);

        $warnings = $result->getWarnings();
        expect($warnings)->not->toBeEmpty('Expected a clearing warning to be raised.');
        $combined = implode("\n", $warnings);
        expect($combined)->toContain('12th Stepper is not set')
            ->toContain('Area')
            ->toContain('Accepts');

        unlink($path);
    });

    it('does not warn when not a 12th stepper and area and accepts are empty', function () {
        $path = memberImportCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation', '12th Stepper', 'Area', 'Accepts'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', '', '', 'no', '', ''],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        expect($result->getCreated())->toEqual(1)
            ->and($result->getWarnings())->toBeEmpty('Did not expect a clearing warning when Area and Accepts are already empty.');

        unlink($path);
    });

    it('skips a row with an unrecognised accepts value', function () {
        $path = memberImportCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation', '12th Stepper', 'Area', 'Accepts'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', '', '', 'yes', 'East London', 'Male|Banana'],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        expect($result->getSkipped())->toEqual(1);
        $skipped = $result->getSkippedRows();
        expect($skipped[0]['reason'])->toContain('Banana')
            ->toContain('Male, Female, Non-Binary, All');

        unlink($path);
    });

    it('accepts labels case-insensitively', function () {
        $path = memberImportCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation', '12th Stepper', 'Area', 'Accepts'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', '', '', 'yes', 'East London', ' male | NON-binary '],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import($path, dryRun: true);

        expect($result->getCreated())->toEqual(1)
            ->and($result->getSkipped())->toEqual(0)
            ->and($result->getWarnings())->toBeEmpty();

        unlink($path);
    });

    it('expands accepts "all" to every concrete value', function () {
        $path = memberImportCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation', '12th Stepper', 'Area', 'Accepts'],
            [
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', '', '', 'yes', 'East London', 'All'],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);
        $this->memberRepo->shouldReceive('findAll')->andReturn([existingImportedMember()]);
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $capturedArgs = null;
        $this->memberFactory->shouldReceive('createNew')
            ->andReturnUsing(function (...$args) use (&$capturedArgs) {
                $capturedArgs = $args;
                return Mockery::mock(Member::class);
            });

        $result = $this->importer->import($path);

        expect($result->getUpdated())->toEqual(1);

        $acceptsArg = null;
        foreach ($capturedArgs as $arg) {
            if (is_array($arg) && in_array('accepts-male', $arg, true)) {
                $acceptsArg = $arg;
                break;
            }
        }
        expect($acceptsArg)->toBe(['accepts-male', 'accepts-female', 'accepts-non-binary'], '"All" should expand to every concrete accepts value.');

        unlink($path);
    });

    it('dedupes when "all" is combined with concrete values', function () {
        $path = memberImportCsv(
            ['Anonymous Name', 'Home Group', 'Personal Email', 'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation', '12th Stepper', 'Area', 'Accepts'],
            [
                // "All|Female" is equivalent to "All" — the expansion already
                // contains Female, so combining them must not produce a duplicate.
                ['Alice A.', 'Group One', 'alice@example.com', '555-0001', 'no', '', '', 'yes', 'East London', 'All|Female'],
            ]
        );

        $this->groupLookup->shouldReceive('resolve')->andReturn(10);
        $this->positionLookup->shouldReceive('resolve')->andReturn(0);
        $this->memberRepo->shouldReceive('findAll')->andReturn([existingImportedMember()]);
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $capturedArgs = null;
        $this->memberFactory->shouldReceive('createNew')
            ->andReturnUsing(function (...$args) use (&$capturedArgs) {
                $capturedArgs = $args;
                return Mockery::mock(Member::class);
            });

        $result = $this->importer->import($path);

        expect($result->getUpdated())->toEqual(1);

        $acceptsArg = null;
        foreach ($capturedArgs as $arg) {
            if (is_array($arg) && in_array('accepts-male', $arg, true)) {
                $acceptsArg = $arg;
                break;
            }
        }
        expect($acceptsArg)->toBe(['accepts-male', 'accepts-female', 'accepts-non-binary']);

        unlink($path);
    });
});
