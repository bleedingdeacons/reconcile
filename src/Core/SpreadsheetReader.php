<?php

declare(strict_types=1);

namespace Reconcile\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * Spreadsheet Reader
 *
 * Reads CSV and XLSX files and yields rows as associative arrays.
 * Keeps memory usage reasonable by streaming CSV line-by-line.
 * XLSX is parsed with a minimal built-in reader (no external library required).
 */
class SpreadsheetReader
{
    /**
     * Maximum uncompressed size (bytes) accepted for any single XML member
     * of the XLSX archive. XLSX files are ZIPs; a 5 KB archive can expand
     * to gigabytes if the compression ratio is adversarial ("zip bomb").
     *
     * 50 MB is well above any legitimate sharedStrings or sheet XML we
     * expect — real spreadsheets with hundreds of thousands of rows come
     * in comfortably under this.
     */
    private const MAX_XML_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;

    /**
     * Maximum compression ratio (uncompressed / compressed) tolerated for
     * any single XML member. Normal text XML compresses ~10x; 200x is a
     * strong signal of a malicious payload crafted to exhaust memory.
     */
    private const MAX_COMPRESSION_RATIO = 200;

    /**
     * Hard cap on the number of rows processed from a sheet (excluding
     * header). XLSX allows up to 1,048,576 rows; loading that many into
     * memory would OOM any reasonable host.
     */
    private const MAX_ROWS = 50_000;

    /**
     * Read a spreadsheet file and return all rows.
     *
     * The first row is treated as headers.
     *
     * @param string $filePath Absolute path to the uploaded file
     * @return array{headers: string[], rows: array<int, string[]>}
     * @throws RuntimeException If the file cannot be read or is unsupported
     */
    public function read(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            $fileName = basename($filePath);
            throw new RuntimeException("File not found or not readable: {$fileName}");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv'  => $this->readCsv($filePath),
            'xlsx' => $this->readXlsx($filePath),
            default => throw new RuntimeException("Unsupported file type: .{$extension}. Please upload a .csv or .xlsx file."),
        };
    }

    /**
     * Read a CSV file.
     */
    private function readCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            throw new RuntimeException("Could not open CSV file: {$filePath}");
        }

        $headers = [];
        $rows = [];
        $lineNumber = 0;

        while (($data = fgetcsv($handle)) !== false) {
            $lineNumber++;

            // Skip completely empty lines
            if (count($data) === 1 && ($data[0] === null || $data[0] === '')) {
                continue;
            }

            if ($lineNumber === 1) {
                // Remove BOM if present
                if (isset($data[0]) && str_starts_with($data[0], "\xEF\xBB\xBF")) {
                    $data[0] = substr($data[0], 3);
                }
                $headers = array_map('trim', $data);
                continue;
            }

            $rows[] = array_map('trim', $data);
        }

        fclose($handle);

        if (empty($headers)) {
            throw new RuntimeException('The CSV file is empty or has no header row.');
        }

        return [
            'headers' => $headers,
            'rows'    => $rows,
        ];
    }

    /**
     * Read an XLSX file using a minimal ZIP + XML parser.
     *
     * This avoids requiring PhpSpreadsheet or similar large libraries.
     */
    private function readXlsx(string $filePath): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP zip extension is required to read .xlsx files.');
        }

        $zip = new \ZipArchive();

        if ($zip->open($filePath) !== true) {
            throw new RuntimeException("Could not open XLSX file: {$filePath}");
        }

        // Zip-bomb defence: inspect the central directory before reading
        // any member. statName() returns uncompressed and compressed sizes
        // without actually decompressing — this is the only cheap way to
        // reject a payload before it blows up memory.
        $this->assertZipMemberSafe($zip, 'xl/sharedStrings.xml', /* required */ false);
        $this->assertZipMemberSafe($zip, 'xl/worksheets/sheet1.xml', /* required */ true);

        // 1. Read shared strings
        $sharedStrings = $this->readSharedStrings($zip);

        // 2. Read the first worksheet (sheet1.xml)
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');

        if ($sheetXml === false) {
            $zip->close();
            throw new RuntimeException('Could not find sheet1.xml in the XLSX file.');
        }

        $zip->close();

        // Disable external entity loading before parsing user-supplied XML
        // to defend against XXE (billion-laughs / external-file reads).
        $previousEntityLoader = null;
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            $previousEntityLoader = libxml_disable_entity_loader(true);
        }

        try {
            $xml = simplexml_load_string(
                $sheetXml,
                'SimpleXMLElement',
                LIBXML_NONET | LIBXML_NOENT
            );
        } finally {
            if ($previousEntityLoader !== null && function_exists('libxml_disable_entity_loader')) {
                libxml_disable_entity_loader($previousEntityLoader);
            }
        }

        if ($xml === false) {
            throw new RuntimeException('Could not parse sheet1.xml.');
        }

        $namespaces = $xml->getNamespaces(true);
        $ns = $namespaces[''] ?? '';

        $headers = [];
        $rows = [];
        $rowIndex = 0;

        foreach ($xml->sheetData->row as $row) {
            $rowIndex++;

            // Row-count guard: refuse to load pathologically large sheets.
            // The -1 accounts for the header row, which is not stored in
            // $rows but is counted by $rowIndex.
            if ($rowIndex - 1 > self::MAX_ROWS) {
                throw new RuntimeException(sprintf(
                    'Spreadsheet exceeds the %s-row limit. Please split the file into smaller batches.',
                    number_format(self::MAX_ROWS)
                ));
            }

            $cells = [];

            foreach ($row->c as $cell) {
                $value = '';
                $type = (string) $cell['t'];

                if ($type === 's') {
                    // Shared string index
                    $ssIndex = (int) (string) $cell->v;
                    $value = $sharedStrings[$ssIndex] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) ($cell->is->t ?? '');
                } else {
                    $value = (string) ($cell->v ?? '');
                }

                // The 'r' attribute holds the cell reference (e.g. "A1", "C3").
                // Parse the column index from it so that sparse rows — where
                // empty cells are omitted from the XML — place values in the
                // correct column rather than packing them sequentially.
                $ref = (string) ($cell['r'] ?? '');
                $colIndex = $ref !== '' ? $this->columnRefToIndex($ref) : count($cells);

                // Fill any gap between the last written column and this one
                while (count($cells) < $colIndex) {
                    $cells[] = '';
                }

                $cells[$colIndex] = trim($value);
            }

            if ($rowIndex === 1) {
                $headers = $cells;
                continue;
            }

            // Pad row to match header count
            while (count($cells) < count($headers)) {
                $cells[] = '';
            }

            $rows[] = $cells;
        }

        if (empty($headers)) {
            throw new RuntimeException('The XLSX file is empty or has no header row.');
        }

        return [
            'headers' => $headers,
            'rows'    => $rows,
        ];
    }

    /**
     * Reject ZIP members whose uncompressed size or compression ratio
     * looks like a zip bomb. Throws if the member exceeds either limit.
     *
     * @param \ZipArchive $zip      Opened archive.
     * @param string      $name     Member path within the archive.
     * @param bool        $required If true, throw when the member is missing.
     *
     * @throws RuntimeException
     */
    private function assertZipMemberSafe(\ZipArchive $zip, string $name, bool $required): void
    {
        $stat = $zip->statName($name);

        if ($stat === false) {
            if ($required) {
                throw new RuntimeException("Required archive member missing: {$name}");
            }
            return;
        }

        $uncompressed = (int) ($stat['size'] ?? 0);
        $compressed   = (int) ($stat['comp_size'] ?? 0);

        if ($uncompressed > self::MAX_XML_UNCOMPRESSED_BYTES) {
            throw new RuntimeException(sprintf(
                'XLSX archive member %s exceeds the uncompressed size limit (%d bytes > %d).',
                $name,
                $uncompressed,
                self::MAX_XML_UNCOMPRESSED_BYTES
            ));
        }

        // Compression ratio check catches bombs that stay under the raw
        // size cap individually but whose compressed-to-uncompressed ratio
        // is implausibly high for real office XML.
        if ($compressed > 0 && ($uncompressed / $compressed) > self::MAX_COMPRESSION_RATIO) {
            throw new RuntimeException(sprintf(
                'XLSX archive member %s has a suspicious compression ratio (%.0fx).',
                $name,
                $uncompressed / $compressed
            ));
        }
    }

    /**
     * Extract shared strings from an XLSX ZIP archive.
     *
     * @return string[]
     */
    private function readSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $sst = simplexml_load_string($xml);

        if ($sst === false) {
            return [];
        }

        $strings = [];

        foreach ($sst->si as $si) {
            // Simple case: <t> element directly
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
                continue;
            }

            // Rich text: concatenate all <r><t> fragments
            $text = '';
            foreach ($si->r as $r) {
                $text .= (string) ($r->t ?? '');
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * Convert an XLSX cell reference to a zero-based column index.
     *
     * Extracts the column letters from a reference like "A1", "C3", or "AA5"
     * and converts them to a zero-based index (A=0, B=1, …, Z=25, AA=26, …).
     *
     * @param string $ref Cell reference (e.g. "B3")
     * @return int Zero-based column index
     */
    private function columnRefToIndex(string $ref): int
    {
        // Strip the row number, leaving only column letters
        $col = preg_replace('/[0-9]/', '', $ref);

        if ($col === '' || $col === null) {
            return 0;
        }

        $col = strtoupper($col);
        $index = 0;

        for ($i = 0, $len = strlen($col); $i < $len; $i++) {
            $index = $index * 26 + (ord($col[$i]) - ord('A') + 1);
        }

        // Convert from 1-based (A=1) to 0-based (A=0)
        return $index - 1;
    }
}