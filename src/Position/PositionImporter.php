<?php

declare(strict_types=1);

namespace Position;

use Core\OperationResult;
use Core\SpreadsheetReader;
use RuntimeException;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionFactory;
use Unity\Positions\Interfaces\PositionRepository;

/**
 * Position Importer
 *
 * Orchestrates the import of position data from a spreadsheet file:
 *  1. Reads the file (CSV or XLSX)
 *  2. Maps column headers to Position properties via PositionColumnMapper
 *  3. Validates required fields (either Position ID or Position Name must be populated)
 *  4. Resolves the target position:
 *     - If Position ID is provided, looks up by ID. If Position Name is also provided,
 *       updates the position title.
 *     - If only Position Name is provided (no ID column or ID is empty), looks up by name.
 *  5. Updates positions through the Unity PositionRepository
 *
 * Rows that cannot be imported are skipped with a "Skipped – [reason]" warning.
 *
 * Returns an OperationResult with counts and any warnings/errors.
 */
class PositionImporter
{
    private ?PositionRepository $positionRepository;
    private ?PositionFactory $positionFactory;
    private PositionColumnMapper $columnMapper;
    private SpreadsheetReader $reader;
    private PositionLookup $positionLookup;

    public function __construct(
        ?PositionRepository $positionRepository,
        ?PositionFactory $positionFactory
    ) {
        $this->positionRepository = $positionRepository;
        $this->positionFactory = $positionFactory;
        $this->columnMapper = new PositionColumnMapper();
        $this->reader = new SpreadsheetReader();
        $this->positionLookup = new PositionLookup($positionRepository);
    }

    /**
     * Run the import from a file path.
     *
     * @param string $filePath Absolute path to the uploaded spreadsheet
     * @param bool $dryRun If true, validate only – do not persist anything
     * @return OperationResult
     */
    public function import(string $filePath, bool $dryRun = false): OperationResult
    {
        $result = new OperationResult();

        error_log('Reconcile PositionImporter: Starting import from ' . $filePath . ' (dry_run=' . ($dryRun ? 'true' : 'false') . ').');

        if ($this->positionRepository === null) {
            error_log('Reconcile PositionImporter: PositionRepository is null.');
            $result->addError('Unity PositionRepository is not available. Is Unity fully configured?');
            return $result;
        }

        if ($this->positionFactory === null) {
            error_log('Reconcile PositionImporter: PositionFactory is null.');
            $result->addError('Unity PositionFactory is not available. Is Unity fully configured?');
            return $result;
        }

        // 1. Read spreadsheet
        try {
            $data = $this->reader->read($filePath);
        } catch (RuntimeException $e) {
            error_log('Reconcile PositionImporter: Failed to read spreadsheet — ' . $e->getMessage());
            $result->addError($e->getMessage());
            return $result;
        }

        $headers = $data['headers'];
        $rows = $data['rows'];

        error_log('Reconcile PositionImporter: Read ' . count($rows) . ' data row(s) with headers: ' . implode(', ', $headers));

        // 2. Map columns
        $mapping = $this->columnMapper->mapHeaders($headers);

        error_log('Reconcile PositionImporter: Column mapping — ' . json_encode($mapping));

        $missing = $this->columnMapper->validateMapping($mapping);

        if (!empty($missing)) {
            $labels = PositionColumnMapper::getPropertyLabels();
            $missingLabels = array_map(fn($p) => $labels[$p] ?? $p, $missing);
            $errorMsg = 'Missing required columns: ' . implode(', ', $missingLabels) . '. '
                . 'Please ensure your spreadsheet has headers matching: '
                . implode(', ', array_map(fn($p) => $labels[$p] ?? $p, array_keys($labels))) . '.';
            error_log('Reconcile PositionImporter: ' . $errorMsg);
            $result->addError($errorMsg);
            return $result;
        }

        $result->setTotalRows(count($rows));

        // 3. Process each row
        foreach ($rows as $rowIndex => $row) {
            $lineNumber = $rowIndex + 2; // +1 for 0-index, +1 for header row

            try {
                $rowData = $this->extractRowData($row, $mapping);

                $rawPositionId = trim($rowData['position_id']);
                $rawPositionName = trim($rowData['position_name']);

                // Validate: at least one of position ID or position name must be provided
                if ($rawPositionId === '' && $rawPositionName === '') {
                    $result->skipRow($lineNumber, 'Both Position ID and Position Name are empty. At least one is required.', $this->buildRowDetails($rowData));
                    continue;
                }

                $existingPosition = null;

                if ($rawPositionId !== '') {
                    // ID was supplied — use it to find the position
                    if (!ctype_digit($rawPositionId)) {
                        $result->skipRow(
                            $lineNumber,
                            "Position ID \"{$rawPositionId}\" is not a valid numeric ID.",
                            $this->buildRowDetails($rowData)
                        );
                        continue;
                    }

                    $positionId = (int) $rawPositionId;
                    $existingPosition = $this->findExistingPosition($positionId);

                    if ($existingPosition === null) {
                        $result->skipRow(
                            $lineNumber,
                            "Position ID {$positionId} does not match an existing position.",
                            $this->buildRowDetails($rowData)
                        );
                        continue;
                    }
                } else {
                    // No ID supplied — use position name to find the position
                    $resolvedId = $this->positionLookup->resolve($rawPositionName);

                    if ($resolvedId === 0) {
                        $result->skipRow(
                            $lineNumber,
                            "Position Name \"{$rawPositionName}\" does not match an existing position.",
                            $this->buildRowDetails($rowData)
                        );
                        continue;
                    }

                    $existingPosition = $this->findExistingPosition($resolvedId);

                    if ($existingPosition === null) {
                        $result->skipRow(
                            $lineNumber,
                            "Position Name \"{$rawPositionName}\" resolved to ID {$resolvedId} but the position could not be loaded.",
                            $this->buildRowDetails($rowData)
                        );
                        continue;
                    }
                }

                // Full context for reporting
                $fullDetails = $this->buildRowDetails(
                    $rowData,
                    $existingPosition->getId()
                );

                if ($dryRun) {
                    $result->incrementUpdated();
                    continue;
                }

                // Persist update
                $saveError = '';
                $saved = $this->updatePosition(
                    $existingPosition,
                    $rowData,
                    $saveError
                );
                if ($saved) {
                    $result->incrementUpdated();
                } else {
                    $resolvedId = $existingPosition->getId();
                    $positionLabel = !empty($rowData['position_name'])
                        ? "\"{$rowData['position_name']}\" (ID: {$resolvedId})"
                        : "ID: {$resolvedId}";
                    $reason = "Failed to update position {$positionLabel}.";
                    if ($saveError !== '') {
                        $reason .= " Error: {$saveError}";
                    }
                    $result->skipRow($lineNumber, $reason, $fullDetails);
                }
            } catch (\Exception $e) {
                $result->skipRow(
                    $lineNumber,
                    $e->getMessage(),
                    isset($rowData) ? $this->buildRowDetails($rowData) : []
                );
            }
        }

        return $result;
    }

    /**
     * Extract a keyed array of property values from a raw row using the column mapping.
     *
     * @param string[] $row
     * @param array<int, string> $mapping
     * @return array<string, string>
     */
    private function extractRowData(array $row, array $mapping): array
    {
        $data = [
            'position_id'       => '',
            'position_name'     => '',
            'email'             => '',
            'minimum_sobriety'  => '',
            'term_years'        => '',
            'short_description' => '',
            'summary'           => '',
        ];

        foreach ($mapping as $colIndex => $property) {
            $data[$property] = $row[$colIndex] ?? '';
        }

        return $data;
    }

    /**
     * Build a details array for a skipped row showing raw CSV values and resolved data.
     *
     * @param array<string, string> $rowData Raw extracted row data
     * @param int|null $existingPositionId Existing position post ID if updating
     * @return array<string, string>
     */
    private function buildRowDetails(
        array $rowData,
        ?int $existingPositionId = null
    ): array {
        $labels = PositionColumnMapper::getPropertyLabels();
        $details = [];

        // Raw CSV values
        foreach ($rowData as $property => $value) {
            $label = $labels[$property] ?? $property;
            $details[$label] = $value !== '' ? $value : '(empty)';
        }

        // Resolved values (only include when available)
        if ($existingPositionId !== null) {
            $details['Existing Position ID'] = (string) $existingPositionId;
        }

        return $details;
    }

    /**
     * Try to find an existing position by its post ID.
     */
    private function findExistingPosition(int $positionId): ?Position
    {
        if ($this->positionRepository === null) {
            return null;
        }

        try {
            return $this->positionRepository->findById($positionId);
        } catch (\Exception $e) {
            error_log('Reconcile: Error finding position by ID – ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update an existing position with imported data.
     *
     * @param Position $existing The existing position
     * @param array<string, string> $rowData The imported row data
     * @param string $errorMessage Populated with error message on failure
     * @return bool Whether the update succeeded
     */
    private function updatePosition(
        Position $existing,
        array $rowData,
        string &$errorMessage = ''
    ): bool {
        $postId = $existing->getId();

        $capturedError = '';

        set_error_handler(function (int $errno, string $errstr) use (&$capturedError): bool {
            $capturedError = $errstr;
            return true;
        });

        try {
            // Update post title only if a position name was provided
            $positionName = trim($rowData['position_name']);
            if ($positionName !== '') {
                $postData = [
                    'ID'          => $postId,
                    'post_title'  => $positionName,
                ];

                $result = wp_update_post($postData, true);

                if (is_wp_error($result)) {
                    $errorMessage = $result->get_error_message();
                    return false;
                }
            }

            $this->saveMetaFields($postId, $rowData);

            return true;
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            return false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Save meta fields for a position post.
     *
     * Only updates fields that are present (non-empty) in the imported row data.
     * Empty fields in the spreadsheet are left unchanged on the existing position.
     *
     * @param int $postId The WordPress post ID
     * @param array<string, string> $rowData The imported row data
     */
    private function saveMetaFields(
        int $postId,
        array $rowData
    ): void {
        $email = trim($rowData['email']);
        if ($email !== '') {
            update_post_meta($postId, 'email', $email);
        }

        $minimumSobriety = trim($rowData['minimum_sobriety']);
        if ($minimumSobriety !== '') {
            update_post_meta($postId, 'minimum_sobriety', $minimumSobriety);
        }

        $termYears = trim($rowData['term_years']);
        if ($termYears !== '') {
            update_post_meta($postId, 'term_years', $termYears);
        }

        $shortDescription = trim($rowData['short_description']);
        if ($shortDescription !== '') {
            update_post_meta($postId, 'short_description', $shortDescription);
        }

        $summary = trim($rowData['summary']);
        if ($summary !== '') {
            update_post_meta($postId, 'summary', $summary);
        }
    }
}