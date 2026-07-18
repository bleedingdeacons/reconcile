<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Reconcile\Core\SpreadsheetReader;
use RuntimeException;

/**
 * Unit tests for SpreadsheetReader.
 *
 * @covers \Reconcile\Core\SpreadsheetReader
 */
class SpreadsheetReaderTest extends TestCase
{
    private SpreadsheetReader $reader;

    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new SpreadsheetReader();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    /**
     * A quoted field ending in a backslash is ordinary data — RFC 4180 has no
     * backslash escape, and neither Excel nor Google Sheets emit one.
     *
     * Under PHP's legacy fgetcsv escape, that trailing backslash escaped its
     * own closing quote: the parser ran on past the end of the row and merged
     * the following record into the same field. Two spreadsheet rows became
     * one mangled row, silently losing a member on import.
     *
     * @test
     */
    public function a_field_ending_in_a_backslash_does_not_swallow_the_next_row(): void
    {
        $path = $this->writeRawCsv(
            "Anonymous Name,Area\n"
            . "\"Alice A.\",\"ends with backslash\\\"\n"
            . "\"Bob B.\",\"North\"\n"
        );

        $data = $this->reader->read($path);

        $this->assertSame(['Anonymous Name', 'Area'], $data['headers']);
        $this->assertCount(2, $data['rows'], 'Both member rows must survive parsing.');
        $this->assertSame('Alice A.', $data['rows'][0][0]);
        $this->assertSame('Bob B.', $data['rows'][1][0], 'The second member must not be swallowed by the first.');
        $this->assertSame('North', $data['rows'][1][1]);
    }

    /**
     * A backslash mid-field is data, not an escape character.
     *
     * @test
     */
    public function a_backslash_inside_a_field_is_preserved_verbatim(): void
    {
        $path = $this->writeRawCsv(
            "Anonymous Name,Area\n"
            . "\"Alice A.\",\"North\\South\"\n"
        );

        $data = $this->reader->read($path);

        $this->assertSame('North\\South', $data['rows'][0][1]);
    }

    /**
     * The standard RFC 4180 escape — a doubled quote inside a quoted field —
     * must still work.
     *
     * @test
     */
    public function a_doubled_quote_inside_a_quoted_field_is_unescaped(): void
    {
        $path = $this->writeRawCsv(
            "Anonymous Name,Area\n"
            . "\"Alice \"\"Ally\"\" A.\",\"North\"\n"
        );

        $data = $this->reader->read($path);

        $this->assertSame('Alice "Ally" A.', $data['rows'][0][0]);
        $this->assertSame('North', $data['rows'][0][1]);
    }

    /**
     * @test
     */
    public function it_strips_a_utf8_bom_from_the_first_header(): void
    {
        $path = $this->writeRawCsv("\xEF\xBB\xBFAnonymous Name,Area\n\"Alice A.\",\"North\"\n");

        $data = $this->reader->read($path);

        $this->assertSame('Anonymous Name', $data['headers'][0]);
    }

    /**
     * @test
     */
    public function it_skips_completely_empty_lines(): void
    {
        $path = $this->writeRawCsv("Anonymous Name,Area\n\"Alice A.\",\"North\"\n\n\"Bob B.\",\"South\"\n");

        $data = $this->reader->read($path);

        $this->assertCount(2, $data['rows']);
    }

    /**
     * @test
     */
    public function it_rejects_a_file_with_no_header_row(): void
    {
        $path = $this->writeRawCsv('');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty or has no header row');

        $this->reader->read($path);
    }

    /**
     * @test
     */
    public function it_rejects_an_unsupported_extension(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reader_test_') . '.txt';
        file_put_contents($path, 'nope');
        $this->tempFiles[] = $path;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported file type');

        $this->reader->read($path);
    }

    /**
     * @test
     */
    public function it_rejects_a_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File not found');

        $this->reader->read(sys_get_temp_dir() . '/definitely-not-here-' . uniqid() . '.csv');
    }

    /**
     * Write raw CSV text (not via fputcsv, so the byte-level quoting under
     * test is exactly what a spreadsheet application would produce).
     */
    private function writeRawCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'reader_test_') . '.csv';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
