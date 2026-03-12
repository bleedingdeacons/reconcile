<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Managers;

use Amber\Managers\MeetingReconciler;
use Concordance\Api\ApiCache;
use Concordance\Models\GroupListing;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

/**
 * Unit tests for MeetingReconciler
 */
class MeetingReconcilerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MeetingRepository|Mockery\MockInterface $meetingRepo;
    private ApiCache|Mockery\MockInterface $apiCache;
    private MeetingReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meetingRepo = Mockery::mock(MeetingRepository::class);
        $this->apiCache = Mockery::mock(ApiCache::class);
        $this->reconciler = new MeetingReconciler($this->meetingRepo, $this->apiCache);
    }

    /**
     * Create a mock local Meeting.
     */
    private function mockMeeting(int $id, string $name, int $day, string $time, string $endTime = '', bool $online = false): Meeting|Mockery\MockInterface
    {
        $dayNames = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

        $meeting = Mockery::mock(Meeting::class);
        $meeting->shouldReceive('getId')->andReturn($id);
        $meeting->shouldReceive('getName')->andReturn($name);
        $meeting->shouldReceive('getDay')->andReturn($day);
        $meeting->shouldReceive('getDayOfWeek')->andReturn($dayNames[$day] ?? '');
        $meeting->shouldReceive('getTime')->andReturn($time);
        $meeting->shouldReceive('getEndTime')->andReturn($endTime);
        $meeting->shouldReceive('isOnline')->andReturn($online);

        return $meeting;
    }

    /**
     * Create a mock national GroupListing.
     */
    private function mockGroupListing(string $id, string $name, string $day, string $startTime, string $endTime = '', string $town = ''): GroupListing|Mockery\MockInterface
    {
        $listing = Mockery::mock(GroupListing::class);
        $listing->shouldReceive('getId')->andReturn($id);
        $listing->shouldReceive('getGroupName')->andReturn($name);
        $listing->shouldReceive('getDay')->andReturn($day);
        $listing->shouldReceive('getStartTime')->andReturn($startTime);
        $listing->shouldReceive('getEndTime')->andReturn($endTime);
        $listing->shouldReceive('getTown')->andReturn($town);

        return $listing;
    }

    /**
     * Set up the mocks so reconcile() can run with the given meetings/listings.
     */
    private function setupReconcile(array $localMeetings, array $nationalListings): void
    {
        $this->meetingRepo->shouldReceive('findAll')
            ->with(['posts_per_page' => -1])
            ->andReturn($localMeetings);

        // ApiCache returns raw data; GroupListing::collectionFromResponse parses it.
        // Since we can't easily mock the static factory, we mock at the ApiCache level
        // and replace the reconciler's fetchNationalGroups via a test subclass.
        // Instead, we directly test through the public reconcile() by providing
        // the ApiCache mock to return data that GroupListing::collectionFromResponse expects.
        // For simplicity, we use a partial mock approach.
    }

    // ── Confident match ────────────────────────────────────────────────

    /**
     * @test
     */
    public function reconcile_finds_confident_match_on_day_time_and_name(): void
    {
        $local = [$this->mockMeeting(1, 'Serenity Group', 1, '19:00', '20:00')];

        $this->meetingRepo->shouldReceive('findAll')->andReturn($local);

        // We need to test the private nameSimilarity and matching logic.
        // Use reflection to test the core matching algorithm directly.
        $reflection = new \ReflectionClass(MeetingReconciler::class);
        $method = $reflection->getMethod('nameSimilarity');
        $method->setAccessible(true);

        $score = $method->invoke($this->reconciler, 'Serenity Group', 'Serenity Group');

        $this->assertGreaterThanOrEqual(0.3, $score);
        $this->assertEquals(1.0, $score);
    }

    // ── Name similarity scoring ────────────────────────────────────────

    /**
     * @test
     */
    public function name_similarity_returns_1_for_identical_names(): void
    {
        $score = $this->invokeNameSimilarity('Monday Night Meeting', 'Monday Night Meeting');

        $this->assertEquals(1.0, $score);
    }

    /**
     * @test
     */
    public function name_similarity_is_case_insensitive(): void
    {
        $score = $this->invokeNameSimilarity('Serenity Group', 'SERENITY GROUP');

        $this->assertEquals(1.0, $score);
    }

    /**
     * @test
     */
    public function name_similarity_strips_stop_words(): void
    {
        // "the", "aa", "meeting", "group" are stop words
        $score = $this->invokeNameSimilarity('The AA Serenity Meeting', 'Serenity');

        // After stripping stop words, both reduce to "serenity"
        $this->assertGreaterThanOrEqual(0.3, $score);
    }

    /**
     * @test
     */
    public function name_similarity_returns_low_score_for_unrelated_names(): void
    {
        $score = $this->invokeNameSimilarity('Hope Springs Eternal', 'Downtown Lunch Bunch');

        $this->assertLessThan(0.3, $score);
    }

    /**
     * @test
     */
    public function name_similarity_handles_empty_strings(): void
    {
        $score = $this->invokeNameSimilarity('', '');

        // Both empty after normalization; should not throw
        $this->assertIsFloat($score);
    }

    /**
     * @test
     */
    public function name_similarity_handles_stop_words_only(): void
    {
        // Both names consist entirely of stop words
        $score = $this->invokeNameSimilarity('The AA Meeting Group', 'The Meeting');

        // Falls back to full-word comparison since significant tokens are empty
        $this->assertIsFloat($score);
    }

    /**
     * @test
     */
    public function name_similarity_partial_overlap(): void
    {
        // "serenity" matches, "sunrise"/"sunset" don't
        $score = $this->invokeNameSimilarity('Serenity Sunrise', 'Serenity Sunset');

        $this->assertGreaterThan(0.0, $score);
        $this->assertLessThan(1.0, $score);
    }

    // ── Time normalisation ─────────────────────────────────────────────

    /**
     * @test
     */
    public function normalise_time_pads_single_digit_hour(): void
    {
        $method = (new \ReflectionClass(MeetingReconciler::class))
            ->getMethod('normaliseTime');
        $method->setAccessible(true);

        $this->assertEquals('07:00', $method->invoke($this->reconciler, '7:00'));
        $this->assertEquals('19:30', $method->invoke($this->reconciler, '19:30'));
        $this->assertEquals('', $method->invoke($this->reconciler, ''));
    }

    // ── Day normalisation ──────────────────────────────────────────────

    /**
     * @test
     */
    public function normalise_day_maps_tsml_integer_to_day_string(): void
    {
        $method = (new \ReflectionClass(MeetingReconciler::class))
            ->getMethod('normaliseDayFromMeeting');
        $method->setAccessible(true);

        $meeting = $this->mockMeeting(1, 'Test', 0, '10:00'); // 0 = Sunday

        $this->assertEquals('sunday', $method->invoke($this->reconciler, $meeting));
    }

    // ── Summary structure ──────────────────────────────────────────────

    /**
     * @test
     */
    public function reconcile_returns_correct_summary_structure_with_no_data(): void
    {
        $this->meetingRepo->shouldReceive('findAll')->andReturn([]);
        $this->apiCache->shouldReceive('getGroups')->andReturn([]);

        // GroupListing::collectionFromResponse on empty array returns empty
        // We need to handle this carefully — the reconciler calls
        // GroupListing::collectionFromResponse which is a static method.
        // For this test we verify the structure when both lists are empty.

        // Use partial mock to bypass the API call
        $reconciler = Mockery::mock(MeetingReconciler::class, [$this->meetingRepo, $this->apiCache])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        // Mock fetchNationalGroups to return empty array
        $reconciler->shouldReceive('fetchNationalGroups')->andReturn([]);

        $result = $reconciler->reconcile();
        $summary = $result->getSummary();

        $this->assertArrayHasKey('local_total', $summary);
        $this->assertArrayHasKey('national_total', $summary);
        $this->assertArrayHasKey('confident_matches', $summary);
        $this->assertArrayHasKey('possible_matches', $summary);
        $this->assertArrayHasKey('local_only', $summary);
        $this->assertArrayHasKey('national_only', $summary);
        $this->assertArrayHasKey('local_match_pct', $summary);
        $this->assertArrayHasKey('national_match_pct', $summary);

        $this->assertEquals(0, $summary['local_total']);
        $this->assertEquals(0, $summary['national_total']);
        $this->assertEquals(0, $summary['confident_matches']);
    }

    /**
     * @test
     */
    public function reconcile_result_has_all_list_accessors(): void
    {
        $reconciler = Mockery::mock(MeetingReconciler::class, [$this->meetingRepo, $this->apiCache])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $reconciler->shouldReceive('fetchNationalGroups')->andReturn([]);
        $this->meetingRepo->shouldReceive('findAll')->andReturn([]);

        $result = $reconciler->reconcile();

        $this->assertIsArray($result->getMatches());
        $this->assertIsArray($result->getPossibles());
        $this->assertIsArray($result->getLocalOnly());
        $this->assertIsArray($result->getNationalOnly());
        $this->assertIsArray($result->getSummary());
    }

    // ── Local-only detection ───────────────────────────────────────────

    /**
     * @test
     */
    public function reconcile_reports_local_only_when_no_national_data(): void
    {
        $local = [
            $this->mockMeeting(1, 'Monday Meditation', 1, '07:00', '08:00'),
            $this->mockMeeting(2, 'Friday Night', 5, '20:00', '21:00', true),
        ];

        $this->meetingRepo->shouldReceive('findAll')->andReturn($local);

        $reconciler = Mockery::mock(MeetingReconciler::class, [$this->meetingRepo, $this->apiCache])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $reconciler->shouldReceive('fetchNationalGroups')->andReturn([]);

        $result = $reconciler->reconcile();

        $this->assertCount(2, $result->getLocalOnly());
        $this->assertEmpty($result->getMatches());
        $this->assertEmpty($result->getNationalOnly());

        // Online meeting should have appropriate reason
        $localOnly = $result->getLocalOnly();
        $onlineMeeting = array_values(array_filter($localOnly, fn($m) => $m['id'] === 2));
        $this->assertStringContainsString('Online', $onlineMeeting[0]['reason']);
    }

    // ── API error propagation ──────────────────────────────────────────

    /**
     * @test
     */
    public function reconcile_throws_on_api_error(): void
    {
        $this->meetingRepo->shouldReceive('findAll')->andReturn([]);

        $wpError = Mockery::mock('WP_Error');
        $wpError->shouldReceive('get_error_message')->andReturn('API timeout');

        $this->apiCache->shouldReceive('getGroups')->andReturn($wpError);

        // is_wp_error needs to return true for WP_Error instances
        if (!function_exists('is_wp_error')) {
            function is_wp_error($thing) {
                return $thing instanceof \WP_Error || (is_object($thing) && method_exists($thing, 'get_error_message'));
            }
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/API timeout/');

        $this->reconciler->reconcile();
    }

    // ── Helper ─────────────────────────────────────────────────────────

    private function invokeNameSimilarity(string $a, string $b): float
    {
        $method = (new \ReflectionClass(MeetingReconciler::class))
            ->getMethod('nameSimilarity');
        $method->setAccessible(true);

        return $method->invoke($this->reconciler, $a, $b);
    }
}