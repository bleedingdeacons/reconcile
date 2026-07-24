<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Reconcile\Group\GroupLookup;
use Reconcile\Member\MemberImporter;
use Reconcile\Position\PositionLookup;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberFactory;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\Interfaces\MemberRevisor;

/**
 * MemberImporter persist-path failure and revise() branches: an update whose
 * save fails, a save that throws, and the MemberRevisor path used when an
 * existing member is updated through a bound revisor.
 *
 * @covers \Reconcile\Member\MemberImporter
 */
class MemberImporterFailureTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var MemberRepository&Mockery\MockInterface */
    private $memberRepo;
    /** @var MemberFactory&Mockery\MockInterface */
    private $memberFactory;
    /** @var GroupLookup&Mockery\MockInterface */
    private $groupLookup;
    /** @var PositionLookup&Mockery\MockInterface */
    private $positionLookup;
    /** @var Configuration&Mockery\MockInterface */
    private $configuration;

    private const HEADERS = [
        'Member ID', 'Anonymous Name', 'Home Group', 'Personal Email',
        'Mobile', 'GSR', 'Intergroup Position', 'Intergroup Position Rotation',
    ];

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function importer(?MemberRevisor $revisor = null): MemberImporter
    {
        return new MemberImporter(
            $this->configuration,
            $this->memberRepo,
            $this->memberFactory,
            $this->groupLookup,
            $this->positionLookup,
            $revisor
        );
    }

    /** @return Member&Mockery\MockInterface */
    private function member()
    {
        return Mockery::mock(Member::class)->shouldIgnoreMissing();
    }

    /** @param array<int, array<int, string>> $rows */
    private function writeCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mem_fail_') . '.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, self::HEADERS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);
        return $path;
    }

    /** @test */
    public function an_update_whose_save_returns_false_is_skipped(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(42)->andReturn($this->member());
        $this->memberFactory->shouldReceive('createNew')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->once()->andReturn(false);

        $result = $this->importer()->import($this->writeCsv([
            ['42', 'Existing', '', 'e@example.com', '555', 'no', '', ''],
        ]));

        $this->assertSame(0, $result->getUpdated());
        $this->assertSame(1, $result->getSkipped());
    }

    /** @test */
    public function a_save_that_throws_is_captured_as_a_skip(): void
    {
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberFactory->shouldReceive('createNew')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->once()->andThrow(new \RuntimeException('db is on fire'));

        $result = $this->importer()->import($this->writeCsv([
            ['', 'New Member', '', 'n@example.com', '555', 'no', '', ''],
        ]));

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
    }

    protected function clearInsertGlobals(): void
    {
        unset(
            $GLOBALS['__reconcile_test_wp_insert_post_error'],
            $GLOBALS['__reconcile_test_wp_insert_post_returns'],
        );
    }

    /** @test */
    public function a_wp_error_from_post_insert_on_create_is_skipped(): void
    {
        $GLOBALS['__reconcile_test_wp_insert_post_error'] = 'post insert refused';
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);

        $result = $this->importer()->import($this->writeCsv([
            ['', 'New Member', '', 'n@example.com', '555', 'no', '', ''],
        ]));

        $this->assertSame(0, $result->getCreated());
        $this->assertSame(1, $result->getSkipped());
        $this->clearInsertGlobals();
    }

    /** @test */
    public function a_save_that_emits_a_php_warning_records_the_captured_text(): void
    {
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberFactory->shouldReceive('createNew')->andReturn($this->member());
        // The importer wraps save() in an error handler; a warning it emits is
        // captured and appended to the skip reason.
        $this->memberRepo->shouldReceive('save')->once()->andReturnUsing(function (): bool {
            trigger_error('legacy meta written', E_USER_WARNING);
            return false;
        });

        $result = $this->importer()->import($this->writeCsv([
            ['', 'New Member', '', 'n@example.com', '555', 'no', '', ''],
        ]));

        $this->assertSame(1, $result->getSkipped());
    }

    /** @test */
    public function a_name_lookup_swallows_a_repository_exception(): void
    {
        // findAll() throwing during the name-based existence check must degrade
        // to "not found" (treat as a create) rather than aborting the import.
        $this->memberRepo->shouldReceive('findAll')->andThrow(new \RuntimeException('query failed'));
        $this->memberFactory->shouldReceive('createNew')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $result = $this->importer()->import($this->writeCsv([
            ['', 'New Member', '', 'n@example.com', '555', 'no', '', ''],
        ]));

        $this->assertSame(1, $result->getCreated());
    }

    /** @test */
    public function an_id_lookup_swallows_a_repository_exception_and_skips(): void
    {
        // findById() throwing is caught and returns null, so the row is treated
        // as "member not found" and skipped.
        $this->memberRepo->shouldReceive('findById')->with(42)->andThrow(new \RuntimeException('db down'));

        $result = $this->importer()->import($this->writeCsv([
            ['42', 'Existing', '', 'e@example.com', '555', 'no', '', ''],
        ]));

        $this->assertSame(1, $result->getSkipped());
    }

    /** @test */
    public function an_impossible_rotation_date_is_skipped(): void
    {
        // 31 February is not a real date; checkdate() rejects it, so the
        // position row (which requires a valid rotation) is skipped.
        $this->positionLookup->shouldReceive('resolve')->with('Chair')->andReturn(7);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberRepo->shouldNotReceive('save');

        $result = $this->importer()->import($this->writeCsv([
            ['', 'Officer', '', 'o@example.com', '555', 'no', 'Chair', '31/02/2026'],
        ]));

        $this->assertSame(1, $result->getSkipped());
    }

    /** @test */
    public function a_two_digit_year_rotation_date_is_accepted(): void
    {
        // dd/MM/yy is one of the accepted rotation formats.
        $this->positionLookup->shouldReceive('resolve')->with('Chair')->andReturn(7);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->memberFactory->shouldReceive('createNew')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $result = $this->importer()->import($this->writeCsv([
            ['', 'Officer', '', 'o@example.com', '555', 'no', 'Chair', '01/02/26'],
        ]));

        $this->assertSame(1, $result->getCreated());
    }

    /** @test */
    public function an_update_goes_through_the_revisor_when_one_is_bound(): void
    {
        $existing = $this->member();
        $revised  = $this->member();

        $this->memberRepo->shouldReceive('findById')->with(42)->andReturn($existing);

        // With a revisor bound, buildMember() must delegate to revise() rather
        // than rebuilding the member from the factory. createNew() must not be
        // called on the update path.
        $revisor = Mockery::mock(MemberRevisor::class);
        $revisor->shouldReceive('revise')->once()->andReturn($revised);
        $this->memberFactory->shouldNotReceive('createNew');

        $this->memberRepo->shouldReceive('save')->once()->with($revised)->andReturn(true);

        $result = $this->importer($revisor)->import($this->writeCsv([
            ['42', 'Existing', '', 'e@example.com', '555', 'no', '', ''],
        ]));

        $this->assertSame(1, $result->getUpdated());
    }
}
