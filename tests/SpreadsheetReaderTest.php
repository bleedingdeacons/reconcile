<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit\Core;

use Reconcile\Core\SpreadsheetReader;
use RuntimeException;

/*
 * Unit tests for SpreadsheetReader.
 */

covers(SpreadsheetReader::class);

beforeEach(function () {
    $this->reader = new SpreadsheetReader();
    $this->tempFiles = [];

    // Write raw CSV text (not via fputcsv, so the byte-level quoting under
    // test is exactly what a spreadsheet application would produce).
    $this->writeRawCsv = function (string $contents): string {
        $path = tempnam(sys_get_temp_dir(), 'reader_test_') . '.csv';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    };
});

afterEach(function () {
    foreach ($this->tempFiles as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
    $this->tempFiles = [];
});

// A quoted field ending in a backslash is ordinary data — RFC 4180 has no
// backslash escape, and neither Excel nor Google Sheets emit one.
//
// Under PHP's legacy fgetcsv escape, that trailing backslash escaped its
// own closing quote: the parser ran on past the end of the row and merged
// the following record into the same field. Two spreadsheet rows became
// one mangled row, silently losing a member on import.
it('does not let a field ending in a backslash swallow the next row', function () {
    $path = ($this->writeRawCsv)(
        "Anonymous Name,Area\n"
        . "\"Alice A.\",\"ends with backslash\\\"\n"
        . "\"Bob B.\",\"North\"\n"
    );

    $data = $this->reader->read($path);

    expect($data['headers'])->toBe(['Anonymous Name', 'Area'])
        ->and($data['rows'])->toHaveCount(2, 'Both member rows must survive parsing.')
        ->and($data['rows'][0][0])->toBe('Alice A.')
        ->and($data['rows'][1][0])->toBe('Bob B.', 'The second member must not be swallowed by the first.')
        ->and($data['rows'][1][1])->toBe('North');
});

// A backslash mid-field is data, not an escape character.
it('preserves a backslash inside a field verbatim', function () {
    $path = ($this->writeRawCsv)(
        "Anonymous Name,Area\n"
        . "\"Alice A.\",\"North\\South\"\n"
    );

    $data = $this->reader->read($path);

    expect($data['rows'][0][1])->toBe('North\\South');
});

// The standard RFC 4180 escape — a doubled quote inside a quoted field —
// must still work.
it('unescapes a doubled quote inside a quoted field', function () {
    $path = ($this->writeRawCsv)(
        "Anonymous Name,Area\n"
        . "\"Alice \"\"Ally\"\" A.\",\"North\"\n"
    );

    $data = $this->reader->read($path);

    expect($data['rows'][0][0])->toBe('Alice "Ally" A.')
        ->and($data['rows'][0][1])->toBe('North');
});

it('strips a UTF-8 BOM from the first header', function () {
    $path = ($this->writeRawCsv)("\xEF\xBB\xBFAnonymous Name,Area\n\"Alice A.\",\"North\"\n");

    $data = $this->reader->read($path);

    expect($data['headers'][0])->toBe('Anonymous Name');
});

it('skips completely empty lines', function () {
    $path = ($this->writeRawCsv)("Anonymous Name,Area\n\"Alice A.\",\"North\"\n\n\"Bob B.\",\"South\"\n");

    $data = $this->reader->read($path);

    expect($data['rows'])->toHaveCount(2);
});

it('rejects a file with no header row', function () {
    $path = ($this->writeRawCsv)('');

    $this->reader->read($path);
})->throws(RuntimeException::class, 'empty or has no header row');

it('rejects an unsupported extension', function () {
    $path = tempnam(sys_get_temp_dir(), 'reader_test_') . '.txt';
    file_put_contents($path, 'nope');
    $this->tempFiles[] = $path;

    $this->reader->read($path);
})->throws(RuntimeException::class, 'Unsupported file type');

it('rejects a missing file', function () {
    $this->reader->read(sys_get_temp_dir() . '/definitely-not-here-' . uniqid() . '.csv');
})->throws(RuntimeException::class, 'File not found');
