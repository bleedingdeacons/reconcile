<?php

declare(strict_types=1);

namespace Reconcile\Core;

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

                $cells[] = trim($value);
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
}
