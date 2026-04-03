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
            throw new RuntimeException("File not found or not readable: {$filePath}");
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

        // 1. Read shared strings
        $sharedStrings = $this->readSharedStrings($zip);

        // 2. Read the first worksheet (sheet1.xml)
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');

        if ($sheetXml === false) {
            $zip->close();
            throw new RuntimeException('Could not find sheet1.xml in the XLSX file.');
        }

        $zip->close();

        $xml = simplexml_load_string($sheetXml);

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