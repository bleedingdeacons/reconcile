<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reconcile\Core\SpreadsheetReader;
use RuntimeException;

/**
 * Tests for SpreadsheetReader's XLSX path (the CSV path is covered by
 * SpreadsheetReaderTest). A minimal .xlsx is assembled with ZipArchive so no
 * fixture files or PhpSpreadsheet are required.
 *
 * @covers \Reconcile\Core\SpreadsheetReader
 * @requires extension zip
 */
class SpreadsheetReaderXlsxTest extends TestCase
{
    /** @var string[] */
    private array $cleanup = [];

    private SpreadsheetReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new SpreadsheetReader();
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->cleanup = [];
        parent::tearDown();
    }

    private function writeXlsx(?string $sharedStrings, ?string $sheet): string
    {
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
    }

    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private function sst(array $strings): string
    {
        $items = '';
        foreach ($strings as $s) {
            $items .= '<si><t>' . htmlspecialchars($s) . '</t></si>';
        }
        return '<?xml version="1.0"?><sst xmlns="' . self::NS . '">' . $items . '</sst>';
    }

    private function sheet(string $rows): string
    {
        return '<?xml version="1.0"?><worksheet xmlns="' . self::NS . '"><sheetData>'
            . $rows . '</sheetData></worksheet>';
    }

    /**
     * @test
     */
    public function it_reads_shared_string_cells(): void
    {
        $path = $this->writeXlsx(
            $this->sst(['Name', 'Email', 'Alice', 'alice@example.com']),
            $this->sheet(
                '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
                . '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row>'
            )
        );

        $data = $this->reader->read($path);

        $this->assertSame(['Name', 'Email'], $data['headers']);
        $this->assertSame([['Alice', 'alice@example.com']], $data['rows']);
    }

    /**
     * @test
     */
    public function it_reads_inline_strings_and_fills_sparse_columns(): void
    {
        // Row 2 omits column A, so a value in column B must land in index 1.
        $path = $this->writeXlsx(
            null,
            $this->sheet(
                '<row r="1"><c r="A1" t="inlineStr"><is><t>Col A</t></is></c>'
                . '<c r="B1" t="inlineStr"><is><t>Col B</t></is></c></row>'
                . '<row r="2"><c r="B2" t="inlineStr"><is><t>only B</t></is></c></row>'
            )
        );

        $data = $this->reader->read($path);

        $this->assertSame(['Col A', 'Col B'], $data['headers']);
        // Column A gap-filled with '' before the B value.
        $this->assertSame([['', 'only B']], $data['rows']);
    }

    /**
     * @test
     */
    public function it_concatenates_rich_text_shared_strings(): void
    {
        $sst = '<?xml version="1.0"?><sst xmlns="' . self::NS . '">'
            . '<si><r><t>Rich</t></r><r><t>Text</t></r></si>'
            . '<si><t>Plain</t></si>'
            . '</sst>';

        $path = $this->writeXlsx(
            $sst,
            $this->sheet(
                '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
                . '<row r="2"><c r="A2"><v>x</v></c><c r="B2"><v>y</v></c></row>'
            )
        );

        $data = $this->reader->read($path);

        // The rich-text runs are joined into a single header value.
        $this->assertSame(['RichText', 'Plain'], $data['headers']);
    }

    /**
     * @test
     */
    public function an_empty_worksheet_is_an_error(): void
    {
        $path = $this->writeXlsx(null, $this->sheet(''));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty or has no header row');
        $this->reader->read($path);
    }

    /**
     * @test
     */
    public function a_missing_worksheet_is_an_error(): void
    {
        // sharedStrings present but no sheet1.xml.
        $path = $this->writeXlsx($this->sst(['x']), null);

        $this->expectException(RuntimeException::class);
        $this->reader->read($path);
    }

    /**
     * @test
     */
    public function an_unreadable_file_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File not found');
        $this->reader->read('/no/such/file.xlsx');
    }
}
