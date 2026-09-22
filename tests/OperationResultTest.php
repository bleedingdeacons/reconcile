<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Reconcile\Core\OperationResult;

/*
 * Tests for OperationResult.
 */

covers(OperationResult::class);

it('tracks state through its counters and getters', function () {
    $result = new OperationResult();
    $result->setTotalRows(10);
    $result->incrementCreated();
    $result->incrementCreated();
    $result->incrementUpdated();
    $result->incrementSkipped();

    expect($result->getTotalRows())->toBe(10)
        ->and($result->getCreated())->toBe(2)
        ->and($result->getUpdated())->toBe(1)
        ->and($result->getSkipped())->toBe(1);
});

it('reports a clean run as a success with a readable summary', function () {
    $result = new OperationResult();
    $result->setTotalRows(3);
    $result->incrementCreated();
    $result->incrementUpdated();

    expect($result->isSuccess())->toBeTrue()
        ->and($result->hasErrors())->toBeFalse()
        ->and($result->getSummary())->toBe('3 row(s) processed, 1 created, 1 updated.');
});

it('lists skipped rows in the summary and the structured list', function () {
    $result = new OperationResult();
    $result->setTotalRows(2);
    $result->skipRow(4, 'Bad data', ['Email' => 'nope']);

    expect($result->getSkipped())->toBe(1)
        ->and($result->getSummary())->toContain('1 skipped')
        ->and($result->getSkippedRows())->toBe([['row' => 4, 'reason' => 'Bad data', 'details' => ['Email' => 'nope']]]);
});

it('makes the run a failure when there are errors', function () {
    $result = new OperationResult();
    $result->addError('Missing required columns');

    expect($result->isSuccess())->toBeFalse()
        ->and($result->hasErrors())->toBeTrue()
        ->and($result->getErrors())->toBe(['Missing required columns'])
        ->and($result->getSummary())->toContain('Import failed');
});

it('tracks warnings independently of errors', function () {
    $result = new OperationResult();
    $result->addWarning('Two members resolved to the same group');

    expect($result->hasWarnings())->toBeTrue()
        ->and($result->getWarnings())->toBe(['Two members resolved to the same group'])
        // Warnings alone do not fail the run.
        ->and($result->isSuccess())->toBeTrue();
});

it('serialises every field in toArray()', function () {
    $result = new OperationResult();
    $result->setTotalRows(5);
    $result->incrementCreated();
    $result->skipRow(2, 'dupe');
    $result->addWarning('w');

    $array = $result->toArray();

    expect($array['success'])->toBeTrue()
        ->and($array['total_rows'])->toBe(5)
        ->and($array['created'])->toBe(1)
        ->and($array['updated'])->toBe(0)
        ->and($array['skipped'])->toBe(1)
        ->and($array['skipped_rows'])->toHaveCount(1)
        ->and($array['warnings'])->toBe(['w'])
        ->and($array)->toHaveKey('summary');
});
