<?php

declare(strict_types=1);

namespace Reconcile\Core;

/**
 * Export or Import Result
 *
 * Holds the outcome of a member/group/position import or export run: counts and messages.
 */
class OperationResult
{
    private int $totalRows = 0;
    private int $created = 0;
    private int $updated = 0;
    private int $skipped = 0;

    /** @var string[] */
    private array $errors = [];

    /** @var string[] */
    private array $warnings = [];

    /**
     * Structured skip reasons: each entry has 'row' (int), 'reason' (string),
     * and optionally 'details' (key-value context about what was being imported).
     *
     * @var array<int, array{row: int, reason: string, details: array<string, string>}>
     */
    private array $skippedRows = [];

    public function setTotalRows(int $count): void
    {
        $this->totalRows = $count;
    }

    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    public function incrementCreated(): void
    {
        $this->created++;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function incrementUpdated(): void
    {
        $this->updated++;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function incrementSkipped(): void
    {
        $this->skipped++;
    }

    /**
     * Record a skipped row with a structured reason.
     *
     * Increments the skipped counter and stores the row/reason pair
     * along with optional context details about the data being imported.
     *
     * @param int $row The spreadsheet row number
     * @param string $reason Human-readable reason for skipping
     * @param array<string, string> $details Key-value pairs of context (e.g. field values, resolved IDs)
     */
    public function skipRow(int $row, string $reason, array $details = []): void
    {
        $this->skipped++;
        $this->skippedRows[] = ['row' => $row, 'reason' => $reason, 'details' => $details];
    }

    /**
     * Get the structured list of skipped rows.
     *
     * @return array<int, array{row: int, reason: string, details: array<string, string>}>
     */
    public function getSkippedRows(): array
    {
        return $this->skippedRows;
    }

    public function getSkipped(): int
    {
        return $this->skipped;
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    /** @return string[] */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** @return string[] */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    /**
     * Whether the import was considered successful (no fatal errors).
     */
    public function isSuccess(): bool
    {
        return !$this->hasErrors();
    }

    /**
     * Get a human-readable summary string.
     */
    public function getSummary(): string
    {
        if ($this->hasErrors()) {
            return 'Import failed: ' . implode(' ', $this->errors);
        }

        $parts = [];
        $parts[] = "{$this->totalRows} row(s) processed";
        $parts[] = "{$this->created} created";
        $parts[] = "{$this->updated} updated";

        if ($this->skipped > 0) {
            $parts[] = "{$this->skipped} skipped";
        }

        return implode(', ', $parts) . '.';
    }

    /**
     * Serialise for JSON response.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success'      => $this->isSuccess(),
            'summary'      => $this->getSummary(),
            'total_rows'   => $this->totalRows,
            'created'      => $this->created,
            'updated'      => $this->updated,
            'skipped'      => $this->skipped,
            'skipped_rows' => $this->skippedRows,
            'errors'       => $this->errors,
            'warnings'     => $this->warnings,
        ];
    }
}
