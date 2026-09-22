<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Reconcile\Core\SpreadsheetReader;
use RuntimeException;

/*
 * Tests for SpreadsheetReader's XLSX path (the CSV path is covered by
 * SpreadsheetReaderTest). A minimal .xlsx is assembled with ZipArchive so no
 * fixture files or PhpSpreadsheet are required.
 */

covers(SpreadsheetReader::class);

const XLSX_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

function xlsxSharedStrings(array $strings): string
{
    $items = '';
    foreach ($strings as $s) {
        $items .= '<si><t>' . htmlspecialchars($s) . '</t></si>';
    }
    return '<?xml version="1.0"?><sst xmlns="' . XLSX_NS . '">' . $items . '</sst>';
}

function xlsxSheet(string $rows): string
{
    return '<?xml version="1.0"?><worksheet xmlns="' . XLSX_NS . '"><sheetData>'
        . $rows . '</sheetData></worksheet>';
}

beforeEach(function () {
    // Was #[RequiresPhpExtension('zip')] on the class: every test here builds
    // its workbook with ZipArchive.
    if (!extension_loaded('zip')) {
        $this->markTestSkipped('Requires the zip extension.');
    }

    $this->reader = new SpreadsheetReader();
    $this->cleanup = [];

    $this->writeXlsx = function (?string $sharedStrings, ?string $sheet): string {
        $base = tempnam(sys_get_temp_dir(), 'xlsx_');
        // ZipArchive needs to create the archive itself; a pre-existing empty
        // (non-zip) file trips up OVERWRITE on some builds.
        @unlink($base);
        $path = $base . '.xlsx';
        $this->cleanup[] = $path;

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->fail('Could not create test xlsx archive.');
        }
        if ($sharedStrings !== null) {
            $zip->addFromString('xl/sharedStrings.xml', $sharedStrings);
        }
        if ($sheet !== null) {
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        }
        $zip->close();

        return $path;
    };
});

afterEach(function () {
    foreach ($this->cleanup as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    $this->cleanup = [];
});

it('reads shared string cells', function () {
    $path = ($this->writeXlsx)(
        xlsxSharedStrings(['Name', 'Email', 'Alice', 'alice@example.com']),
        xlsxSheet(
            '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
            . '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row>'
        )
    );

    $data = $this->reader->read($path);

    expect($data['headers'])->toBe(['Name', 'Email'])
        ->and($data['rows'])->toBe([['Alice', 'alice@example.com']]);
});

it('reads inline strings and fills sparse columns', function () {
    // Row 2 omits column A, so a value in column B must land in index 1.
    $path = ($this->writeXlsx)(
        null,
        xlsxSheet(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>Col A</t></is></c>'
            . '<c r="B1" t="inlineStr"><is><t>Col B</t></is></c></row>'
            . '<row r="2"><c r="B2" t="inlineStr"><is><t>only B</t></is></c></row>'
        )
    );

    $data = $this->reader->read($path);

    expect($data['headers'])->toBe(['Col A', 'Col B'])
        // Column A gap-filled with '' before the B value.
        ->and($data['rows'])->toBe([['', 'only B']]);
});

it('concatenates rich-text shared strings', function () {
    $sst = '<?xml version="1.0"?><sst xmlns="' . XLSX_NS . '">'
        . '<si><r><t>Rich</t></r><r><t>Text</t></r></si>'
        . '<si><t>Plain</t></si>'
        . '</sst>';

    $path = ($this->writeXlsx)(
        $sst,
        xlsxSheet(
            '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
            . '<row r="2"><c r="A2"><v>x</v></c><c r="B2"><v>y</v></c></row>'
        )
    );

    $data = $this->reader->read($path);

    // The rich-text runs are joined into a single header value.
    expect($data['headers'])->toBe(['RichText', 'Plain']);
});

it('reports an empty worksheet as an error', function () {
    $path = ($this->writeXlsx)(null, xlsxSheet(''));

    $this->reader->read($path);
})->throws(RuntimeException::class, 'empty or has no header row');

it('reports a missing worksheet as an error', function () {
    // sharedStrings present but no sheet1.xml.
    $path = ($this->writeXlsx)(xlsxSharedStrings(['x']), null);

    $this->reader->read($path);
})->throws(RuntimeException::class);

it('reports an unreadable file as an error', function () {
    $this->reader->read('/no/such/file.xlsx');
})->throws(RuntimeException::class, 'File not found');
