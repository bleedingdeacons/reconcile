<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use function Brain\Monkey\Functions\when;
use Mockery;
use Reconcile\Group\GroupLookup;
use Reconcile\Member\MemberImporter;
use Reconcile\Position\PositionLookup;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberFactory;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\PreferredContact;

/*
 * Exercises MemberImporter's real (non-dry-run) persist path: create, update
 * and save-failure. The dry-run and validation branches are covered by
 * MemberImporterTest.
 */

covers(MemberImporter::class);

const MEMBER_PERSIST_HEADERS = [
    'Member ID', 'Anonymous Name', 'Home Group', 'Personal Email',
    'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation',
];

const MEMBER_PERSIST_CONTACT_HEADERS = [
    'Member ID', 'Anonymous Name', 'Home Group', 'Personal Email',
    'Mobile', 'Landline', 'Preferred Contact', 'GSR',
    'Intergroup Position', 'Intergroup Position Rotation',
];

const MEMBER_PERSIST_FULL_HEADERS = [
    'Member ID', 'Anonymous Name', 'Home Group', 'Personal Email',
    'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation',
    '12th Stepper', 'Area', 'Accepts',
];

/**
 * @param array<int, array<int, string>> $rows
 * @param string[] $headers
 */
function memberPersistCsv(array $rows, array $headers = MEMBER_PERSIST_HEADERS): string
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
function persistedMember()
{
    return Mockery::mock(Member::class)->shouldIgnoreMissing();
}

beforeEach(function () {
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
});

it('creates a new member when the name is unknown', function () {
    // No Member ID, name not found → create path.
    $this->memberRepo->shouldReceive('findAll')->andReturn([]);
    $this->memberFactory->shouldReceive('createNew')->andReturn(persistedMember());
    $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

    $result = $this->importer->import(memberPersistCsv([
        ['', 'New Member', '', 'new@example.com', '555', 'no', '', ''],
    ]));

    expect($result->getCreated())->toBe(1)
        ->and($result->getSkipped())->toBe(0);
});

it('creates a member with 12th stepper, area and accepts', function () {
    // Exercises the optional-column parsing (12th-stepper flag, area text,
    // and the pipe-separated accepts list).
    $this->memberRepo->shouldReceive('findAll')->andReturn([]);
    $this->memberFactory->shouldReceive('createNew')->andReturn(persistedMember());
    $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

    $result = $this->importer->import(memberPersistCsv([
        ['', 'Stepper', '', 's@example.com', '555', 'no', '', '', 'yes', 'North London', 'male|female|all'],
    ], MEMBER_PERSIST_FULL_HEADERS));

    expect($result->getCreated())->toBe(1);
});

// ─── landline and preferred contact ─────────────────────────────
describe('landline and preferred contact', function () {
    it('passes the landline and preference to the factory', function () {
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $captured = [];
        $this->memberFactory->shouldReceive('createNew')->andReturnUsing(
            function (...$args) use (&$captured) {
                $captured = $args;
                return persistedMember();
            }
        );

        $result = $this->importer->import(memberPersistCsv([
            ['', 'New Member', '', 'new@example.com', '555', '0117 496 0000', 'Landline', 'no', '', ''],
        ], MEMBER_PERSIST_CONTACT_HEADERS));

        expect($result->getCreated())->toBe(1)
            ->and($captured)->toContain('0117 496 0000')
            ->and($captured)->toContain(PreferredContact::Landline);
    });

    // A typo is not an instruction. Anything the column does not recognise
    // falls back to Mobile on a create, rather than guessing.
    it('falls back to Mobile for an unrecognised preference', function () {
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $captured = [];
        $this->memberFactory->shouldReceive('createNew')->andReturnUsing(
            function (...$args) use (&$captured) {
                $captured = $args;
                return persistedMember();
            }
        );

        $this->importer->import(memberPersistCsv([
            ['', 'New Member', '', 'new@example.com', '555', '0117 496 0000', 'Home Phone', 'no', '', ''],
        ], MEMBER_PERSIST_CONTACT_HEADERS));

        expect($captured)->toContain(PreferredContact::Mobile)
            ->not->toContain(PreferredContact::Landline);
    });

    // Matching is case-insensitive: "landline" is plainly asking for the
    // same thing as "Landline".
    it('reads the preference column case-insensitively', function () {
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $captured = [];
        $this->memberFactory->shouldReceive('createNew')->andReturnUsing(
            function (...$args) use (&$captured) {
                $captured = $args;
                return persistedMember();
            }
        );

        $this->importer->import(memberPersistCsv([
            ['', 'New Member', '', 'new@example.com', '555', '0117 496 0000', ' landline ', 'no', '', ''],
        ], MEMBER_PERSIST_CONTACT_HEADERS));

        expect($captured)->toContain(PreferredContact::Landline);
    });

    // A spreadsheet written before these columns existed must not erase
    // either field. On the create path that means the type defaults; the
    // update path is covered in MemberImporterTest, where a revisor is
    // wired and null means "carry over".
    it('creates with the defaults from a spreadsheet without the columns', function () {
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $captured = [];
        $this->memberFactory->shouldReceive('createNew')->andReturnUsing(
            function (...$args) use (&$captured) {
                $captured = $args;
                return persistedMember();
            }
        );

        $this->importer->import(memberPersistCsv([
            ['', 'New Member', '', 'new@example.com', '555', 'no', '', ''],
        ]));

        expect($captured)->toContain(PreferredContact::Mobile)
            ->not->toContain(PreferredContact::Landline);
    });

    it('updates a member found by ID', function () {
        $this->memberRepo->shouldReceive('findById')->with(42)->andReturn(persistedMember());
        $this->memberFactory->shouldReceive('createNew')->andReturn(persistedMember());
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $result = $this->importer->import(memberPersistCsv([
            ['42', 'Existing', '', 'exists@example.com', '555', 'no', '', ''],
        ]));

        expect($result->getUpdated())->toBe(1);
    });

    it('skips a member ID that does not exist', function () {
        $this->memberRepo->shouldReceive('findById')->with(99)->andReturn(null);

        $result = $this->importer->import(memberPersistCsv([
            ['99', 'Ghost', '', 'ghost@example.com', '555', 'no', '', ''],
        ]));

        expect($result->getSkipped())->toBe(1);
    });

    it('skips a non-numeric member ID', function () {
        $result = $this->importer->import(memberPersistCsv([
            ['abc', 'Bad Id', '', 'bad@example.com', '555', 'no', '', ''],
        ]));

        expect($result->getSkipped())->toBe(1);
    });

    it('creates a member with a resolved position and rotation', function () {
        // A position name that resolves plus a valid rotation exercises the
        // position-resolution and rotation-parsing branches.
        $this->positionLookup->shouldReceive('resolve')->with('Chair')->andReturn(7);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberFactory->shouldReceive('createNew')->andReturn(persistedMember());
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $result = $this->importer->import(memberPersistCsv([
            ['', 'Officer', 'Group One', 'o@example.com', '555', 'yes', 'Chair', '2026-01-01'],
        ]));

        expect($result->getCreated())->toBe(1);
    });

    it('skips a position without a rotation', function () {
        // A resolved position with no rotation date is a validation failure.
        $this->positionLookup->shouldReceive('resolve')->with('Chair')->andReturn(7);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberRepo->shouldNotReceive('save');

        $result = $this->importer->import(memberPersistCsv([
            ['', 'Officer', '', 'o@example.com', '555', 'no', 'Chair', ''],
        ]));

        expect($result->getSkipped())->toBe(1)
            ->and($result->getCreated())->toBe(0);
    });

    it('skips a create whose save fails', function () {
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberFactory->shouldReceive('createNew')->andReturn(persistedMember());
        $this->memberRepo->shouldReceive('save')->once()->andReturn(false);

        $result = $this->importer->import(memberPersistCsv([
            ['', 'New Member', '', 'new@example.com', '555', 'no', '', ''],
        ]));

        expect($result->getCreated())->toBe(0)
            ->and($result->getSkipped())->toBe(1);
    });

    it('skips a create whose post insert fails', function () {
        // wp_insert_post returns 0 → the member post could not be created.
        when('wp_insert_post')->justReturn(0);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer->import(memberPersistCsv([
            ['', 'New Member', '', 'new@example.com', '555', 'no', '', ''],
        ]));

        expect($result->getCreated())->toBe(0)
            ->and($result->getSkipped())->toBe(1);
    });
});
