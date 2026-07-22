<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reconcile\Core\OperationResult;

/**
 * Tests for OperationResult.
 *
 * @covers \Reconcile\Core\OperationResult
 */
class OperationResultTest extends TestCase
{
    /**
     * @test
     */
    public function counters_and_getters_track_state(): void
    {
        $result = new OperationResult();
        $result->setTotalRows(10);
        $result->incrementCreated();
        $result->incrementCreated();
        $result->incrementUpdated();
        $result->incrementSkipped();

        $this->assertSame(10, $result->getTotalRows());
        $this->assertSame(2, $result->getCreated());
        $this->assertSame(1, $result->getUpdated());
        $this->assertSame(1, $result->getSkipped());
    }

    /**
     * @test
     */
    public function a_clean_run_is_a_success_with_a_readable_summary(): void
    {
        $result = new OperationResult();
        $result->setTotalRows(3);
        $result->incrementCreated();
        $result->incrementUpdated();

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->hasErrors());
        $this->assertSame('3 row(s) processed, 1 created, 1 updated.', $result->getSummary());
    }

    /**
     * @test
     */
    public function skipped_rows_appear_in_the_summary_and_structured_list(): void
    {
        $result = new OperationResult();
        $result->setTotalRows(2);
        $result->skipRow(4, 'Bad data', ['Email' => 'nope']);

        $this->assertSame(1, $result->getSkipped());
        $this->assertStringContainsString('1 skipped', $result->getSummary());
        $this->assertSame(
            [['row' => 4, 'reason' => 'Bad data', 'details' => ['Email' => 'nope']]],
            $result->getSkippedRows()
        );
    }

    /**
     * @test
     */
    public function errors_make_the_run_a_failure(): void
    {
        $result = new OperationResult();
        $result->addError('Missing required columns');

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->hasErrors());
        $this->assertSame(['Missing required columns'], $result->getErrors());
        $this->assertStringContainsString('Import failed', $result->getSummary());
    }

    /**
     * @test
     */
    public function warnings_are_tracked_independently_of_errors(): void
    {
        $result = new OperationResult();
        $result->addWarning('Two members resolved to the same group');

        $this->assertTrue($result->hasWarnings());
        $this->assertSame(['Two members resolved to the same group'], $result->getWarnings());
        // Warnings alone do not fail the run.
        $this->assertTrue($result->isSuccess());
    }

    /**
     * @test
     */
    public function to_array_serialises_every_field(): void
    {
        $result = new OperationResult();
        $result->setTotalRows(5);
        $result->incrementCreated();
        $result->skipRow(2, 'dupe');
        $result->addWarning('w');

        $array = $result->toArray();

        $this->assertTrue($array['success']);
        $this->assertSame(5, $array['total_rows']);
        $this->assertSame(1, $array['created']);
        $this->assertSame(0, $array['updated']);
        $this->assertSame(1, $array['skipped']);
        $this->assertCount(1, $array['skipped_rows']);
        $this->assertSame(['w'], $array['warnings']);
        $this->assertArrayHasKey('summary', $array);
    }
}
