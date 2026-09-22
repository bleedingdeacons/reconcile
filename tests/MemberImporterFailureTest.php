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
use Unity\Members\Interfaces\MemberRevisor;

/*
 * MemberImporter persist-path failure and revise() branches: an update whose
 * save fails, a save that throws, and the MemberRevisor path used when an
 * existing member is updated through a bound revisor.
 */

covers(MemberImporter::class);

const MEMBER_FAILURE_HEADERS = [
    'Member ID', 'Anonymous Name', 'Home Group', 'Personal Email',
    'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation',
];

/** @return Member&Mockery\MockInterface */
function failingMember()
{
    return Mockery::mock(Member::class)->shouldIgnoreMissing();
}

/** @param array<int, array<int, string>> $rows */
function memberFailureCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'mem_fail_') . '.csv';
    $handle = fopen($path, 'w');
    fputcsv($handle, MEMBER_FAILURE_HEADERS, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }
    fclose($handle);
    return $path;
}

function clearMemberInsertGlobals(): void
{
}

beforeEach(function () {
    $this->configuration = Mockery::mock(Configuration::class);
    $this->configuration->shouldReceive('getConfig')->andReturn([
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

    $this->importer = function (?MemberRevisor $revisor = null): MemberImporter {
        return new MemberImporter(
            $this->configuration,
            $this->memberRepo,
            $this->memberFactory,
            $this->groupLookup,
            $this->positionLookup,
            $revisor
        );
    };
});

it('skips an update whose save returns false', function () {
    $this->memberRepo->shouldReceive('findById')->with(42)->andReturn(failingMember());
    $this->memberFactory->shouldReceive('createNew')->andReturn(failingMember());
    $this->memberRepo->shouldReceive('save')->once()->andReturn(false);

    $result = ($this->importer)()->import(memberFailureCsv([
        ['42', 'Existing', '', 'e@example.com', '555', 'no', '', ''],
    ]));

    expect($result->getUpdated())->toBe(0)
        ->and($result->getSkipped())->toBe(1);
});

it('captures a save that throws as a skip', function () {
    $this->memberRepo->shouldReceive('findAll')->andReturn([]);
    $this->memberFactory->shouldReceive('createNew')->andReturn(failingMember());
    $this->memberRepo->shouldReceive('save')->once()->andThrow(new \RuntimeException('db is on fire'));

    $result = ($this->importer)()->import(memberFailureCsv([
        ['', 'New Member', '', 'n@example.com', '555', 'no', '', ''],
    ]));

    expect($result->getCreated())->toBe(0)
        ->and($result->getSkipped())->toBe(1);
});

it('skips a create whose post insert returns a WP_Error', function () {
    when('wp_insert_post')->justReturn(new \WP_Error('insert_failed', 'post insert refused'));
    $this->memberRepo->shouldReceive('findAll')->andReturn([]);

    $result = ($this->importer)()->import(memberFailureCsv([
        ['', 'New Member', '', 'n@example.com', '555', 'no', '', ''],
    ]));

    expect($result->getCreated())->toBe(0)
        ->and($result->getSkipped())->toBe(1);
    clearMemberInsertGlobals();
});

it('records the captured text of a PHP warning emitted by a save', function () {
    $this->memberRepo->shouldReceive('findAll')->andReturn([]);
    $this->memberFactory->shouldReceive('createNew')->andReturn(failingMember());
    // The importer wraps save() in an error handler; a warning it emits is
    // captured and appended to the skip reason.
    $this->memberRepo->shouldReceive('save')->once()->andReturnUsing(function (): bool {
        trigger_error('legacy meta written', E_USER_WARNING);
        return false;
    });

    $result = ($this->importer)()->import(memberFailureCsv([
        ['', 'New Member', '', 'n@example.com', '555', 'no', '', ''],
    ]));

    expect($result->getSkipped())->toBe(1);
});

it('swallows a repository exception on a name lookup', function () {
    // findAll() throwing during the name-based existence check must degrade
    // to "not found" (treat as a create) rather than aborting the import.
    $this->memberRepo->shouldReceive('findAll')->andThrow(new \RuntimeException('query failed'));
    $this->memberFactory->shouldReceive('createNew')->andReturn(failingMember());
    $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

    $result = ($this->importer)()->import(memberFailureCsv([
        ['', 'New Member', '', 'n@example.com', '555', 'no', '', ''],
    ]));

    expect($result->getCreated())->toBe(1);
});

it('swallows a repository exception on an ID lookup and skips', function () {
    // findById() throwing is caught and returns null, so the row is treated
    // as "member not found" and skipped.
    $this->memberRepo->shouldReceive('findById')->with(42)->andThrow(new \RuntimeException('db down'));

    $result = ($this->importer)()->import(memberFailureCsv([
        ['42', 'Existing', '', 'e@example.com', '555', 'no', '', ''],
    ]));

    expect($result->getSkipped())->toBe(1);
});

it('skips an impossible rotation date', function () {
    // 31 February is not a real date; checkdate() rejects it, so the
    // position row (which requires a valid rotation) is skipped.
    $this->positionLookup->shouldReceive('resolve')->with('Chair')->andReturn(7);
    $this->memberRepo->shouldReceive('findAll')->andReturn([]);
    $this->memberRepo->shouldNotReceive('save');

    $result = ($this->importer)()->import(memberFailureCsv([
        ['', 'Officer', '', 'o@example.com', '555', 'no', 'Chair', '31/02/2026'],
    ]));

    expect($result->getSkipped())->toBe(1);
});

it('accepts a two-digit-year rotation date', function () {
    // dd/MM/yy is one of the accepted rotation formats.
    $this->positionLookup->shouldReceive('resolve')->with('Chair')->andReturn(7);
    $this->memberRepo->shouldReceive('findAll')->andReturn([]);
    $this->memberFactory->shouldReceive('createNew')->andReturn(failingMember());
    $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

    $result = ($this->importer)()->import(memberFailureCsv([
        ['', 'Officer', '', 'o@example.com', '555', 'no', 'Chair', '01/02/26'],
    ]));

    expect($result->getCreated())->toBe(1);
});

it('sends an update through the revisor when one is bound', function () {
    $existing = failingMember();
    $revised  = failingMember();

    $this->memberRepo->shouldReceive('findById')->with(42)->andReturn($existing);

    // With a revisor bound, buildMember() must delegate to revise() rather
    // than rebuilding the member from the factory. createNew() must not be
    // called on the update path.
    $revisor = Mockery::mock(MemberRevisor::class);
    $revisor->shouldReceive('revise')->once()->andReturn($revised);
    $this->memberFactory->shouldNotReceive('createNew');

    $this->memberRepo->shouldReceive('save')->once()->with($revised)->andReturn(true);

    $result = ($this->importer)($revisor)->import(memberFailureCsv([
        ['42', 'Existing', '', 'e@example.com', '555', 'no', '', ''],
    ]));

    expect($result->getUpdated())->toBe(1);
});

// The regression this guards: landline_number and preferred_contact are
// optional columns, so a spreadsheet written before they existed omits
// them entirely. Passing the blank through as a value would erase every
// member's landline on the next re-import — the same class of bug that
// cost the GDPR consent records. Blank means "leave it alone", so
// revise() is handed null for both.
it('leaves both contact fields alone for a spreadsheet without those columns', function () {
    $existing = failingMember();
    $this->memberRepo->shouldReceive('findById')->with(42)->andReturn($existing);

    $captured = [];
    $revisor = Mockery::mock(MemberRevisor::class);
    $revisor->shouldReceive('revise')->once()->andReturnUsing(
        function (Member $base, ...$args) use (&$captured) {
            $captured = $args;
            return $base;
        }
    );

    $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

    ($this->importer)($revisor)->import(memberFailureCsv([
        ['42', 'Existing', '', 'e@example.com', '555', 'no', '', ''],
    ]));

    // Positional, because that is how the named arguments bind: the two
    // fields sit immediately after the mobile number, and null is what
    // revise() reads as "carry the stored value over".
    expect($captured[9])->toBe('555', 'the mobile is at the expected position')
        ->and($captured[10])->toBeNull('landline left alone')
        ->and($captured[11])->toBeNull('preferred contact left alone');
});
