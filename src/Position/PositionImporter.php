<?php

declare(strict_types=1);

namespace Reconcile\Position;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Reconcile\Core\OperationResult;
use Reconcile\Core\SpreadsheetReader;
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
 *       If no match is found, a new position is created (mirrors the Member importer
 *       behaviour when Member ID is empty).
 *  5. Builds a Position via PositionFactory::createNew(), merging imported row data
 *     over any existing field values (blank cells preserve existing data).
 *  6. Persists the Position via PositionRepository::save() so ACF fields are written
 *     under the canonical field keys.
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

        \Reconcile\Plugin::logDebug('Reconcile PositionImporter: Starting import from ' . $filePath . ' (dry_run=' . ($dryRun ? 'true' : 'false') . ').');

        if ($this->positionRepository === null) {
            \Reconcile\Plugin::logError('Reconcile PositionImporter: PositionRepository is null.');
            $result->addError('Unity PositionRepository is not available. Is Unity fully configured?');
            return $result;
        }

        if ($this->positionFactory === null) {
            \Reconcile\Plugin::logError('Reconcile PositionImporter: PositionFactory is null.');
            $result->addError('Unity PositionFactory is not available. Is Unity fully configured?');
            return $result;
        }

        // 1. Read spreadsheet
        try {
            $data = $this->reader->read($filePath);
        } catch (RuntimeException $e) {
            \Reconcile\Plugin::logError('Reconcile PositionImporter: Failed to read spreadsheet: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $result->addError($e->getMessage());
            return $result;
        }

        $headers = $data['headers'];
        $rows = $data['rows'];

        \Reconcile\Plugin::logDebug('Reconcile PositionImporter: Read ' . count($rows) . ' data row(s) with headers: ' . implode(', ', $headers));

        // 2. Map columns
        $mapping = $this->columnMapper->mapHeaders($headers);

        \Reconcile\Plugin::logDebug('Reconcile PositionImporter: Column mapping — ' . json_encode($mapping));

        $missing = $this->columnMapper->validateMapping($mapping);

        if (!empty($missing)) {
            $labels = PositionColumnMapper::getPropertyLabels();
            $missingLabels = array_map(fn($p) => $labels[$p] ?? $p, $missing);
            $errorMsg = 'Missing required columns: ' . implode(', ', $missingLabels) . '. '
                . 'Please ensure your spreadsheet has headers matching: '
                . implode(', ', array_map(fn($p) => $labels[$p] ?? $p, array_keys($labels))) . '.';
            \Reconcile\Plugin::logError('Reconcile PositionImporter: ' . $errorMsg);
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
                    // No ID supplied — use position name to find the position.
                    // If not found, fall through to creating a new position
                    // (mirrors the Member importer behaviour when Member ID is empty).
                    $resolvedId = $this->positionLookup->resolve($rawPositionName);

                    if ($resolvedId !== 0) {
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
                    // If $resolvedId === 0 we leave $existingPosition null and
                    // fall through to the create path below.
                }

                // Full context for reporting
                $fullDetails = $this->buildRowDetails(
                    $rowData,
                    $existingPosition?->getId()
                );

                if ($dryRun) {
                    if ($existingPosition) {
                        $result->incrementUpdated();
                    } else {
                        $result->incrementCreated();
                    }
                    continue;
                }

                if ($existingPosition) {
                    // Build a merged Position via the factory: row values override
                    // existing ones; blank cells preserve the existing field.
                    $mergedPosition = $this->buildMergedPosition(
                        $existingPosition->getId(),
                        $rowData,
                        $existingPosition
                    );

                    $invalidReason = $this->describeInvalidPosition($mergedPosition);
                    if ($invalidReason !== '') {
                        $resolvedId = $existingPosition->getId();
                        $positionLabel = !empty($rowData['position_name'])
                            ? "\"{$rowData['position_name']}\" (ID: {$resolvedId})"
                            : "ID: {$resolvedId}";
                        $result->skipRow(
                            $lineNumber,
                            "Cannot save position {$positionLabel}: {$invalidReason}",
                            $fullDetails
                        );
                        continue;
                    }

                    $saveError = '';
                    $saved = $this->savePosition($mergedPosition, $saveError);

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
                } else {
                    // Build the candidate Position FIRST so we can validate before
                    // inserting any post. This avoids orphan posts when the row is
                    // missing required fields.
                    $newPosition = $this->buildMergedPosition(
                        0,
                        $rowData,
                        null,
                        $rawPositionName
                    );

                    $invalidReason = $this->describeInvalidPosition($newPosition, true);
                    if ($invalidReason !== '') {
                        $result->skipRow(
                            $lineNumber,
                            "Cannot create new position \"{$rawPositionName}\": {$invalidReason}",
                            $fullDetails
                        );
                        continue;
                    }

                    // Create the post
                    $wpError = '';
                    $postId = $this->createPositionPost($rawPositionName, $wpError);

                    if ($postId === 0) {
                        $reason = "Failed to create WordPress post for position \"{$rawPositionName}\".";
                        if ($wpError !== '') {
                            $reason .= " wp_insert_post error: {$wpError}";
                        }
                        $result->skipRow($lineNumber, $reason, $fullDetails);
                        continue;
                    }

                    // Rebuild the Position with the real post ID so isValid() passes
                    // (it requires id > 0) and the repository writes to the right post.
                    $newPosition = $this->buildMergedPosition(
                        $postId,
                        $rowData,
                        null,
                        $rawPositionName
                    );

                    $saveError = '';
                    $saved = $this->savePosition($newPosition, $saveError);

                    if ($saved) {
                        $result->incrementCreated();
                    } else {
                        $fullDetails['Post ID'] = (string) $postId;
                        $reason = "Post created (#{$postId}) but fields failed to save"
                            . " for position \"{$rawPositionName}\".";
                        if ($saveError !== '') {
                            $reason .= " Error: {$saveError}";
                        }
                        $result->skipRow($lineNumber, $reason, $fullDetails);
                    }

                    // Invalidate the name->ID cache so a later row referencing
                    // this same new position by name resolves to the just-created post.
                    $this->positionLookup->invalidateCache();
                }
            } catch (\Exception $e) {
                $result->skipRow(
                    $lineNumber,
                    $e->getMessage(),
                    isset($rowData) ? $this->buildRowDetails($rowData) : []
                );
            }
        }

        $imported = $result->getCreated() + $result->getUpdated();
        if ($imported > 0 && !$dryRun) {
            /** @see \Scrutiny\Audit\AuditTracker::onPositionImport() */
            do_action('unity/position_import', $imported, 'Position Details');
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
            \Reconcile\Plugin::logError('Reconcile: Error finding position by ID: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return null;
        }
    }

    /**
     * Return a human-readable description of why a Position would fail to save,
     * or '' if it is valid. The repository's save() requires all fields to be
     * populated (see TsmlPosition::isValid()); this lets us surface the missing
     * field to the import operator rather than a generic "save failed".
     *
     * @param Position $position           The position to validate
     * @param bool     $ignoreMissingId    When validating a candidate before the
     *                                     WordPress post has been inserted, the
     *                                     ID will legitimately be 0. Pass true in
     *                                     that case so the ID requirement is not
     *                                     reported as a problem.
     */
    private function describeInvalidPosition(Position $position, bool $ignoreMissingId = false): string
    {
        $missing = [];
        if (!$ignoreMissingId && $position->getId() <= 0) {
            $missing[] = 'Post ID';
        }
        if ($position->getEmail() === '') {
            $missing[] = 'Position Email';
        }
        if ($position->getLongName() === '') {
            $missing[] = 'Position Name';
        }
        if ($position->getShortDescription() === '') {
            $missing[] = 'Short Description';
        }
        if ($position->getSummary() === '') {
            $missing[] = 'Summary';
        }
        if ($position->getMinimumSobriety() < 6) {
            $missing[] = 'Minimum Sobriety (must be at least 6)';
        }
        if ($position->getTermYears() < 1) {
            $missing[] = 'Term Years (must be at least 1)';
        }

        if (!empty($missing)) {
            return 'missing required field(s): ' . implode(', ', $missing) . '.';
        }

        // All known field checks pass. If isValid() still rejects for some
        // other reason, surface a generic message rather than reporting OK.
        // When validating pre-create (ignoreMissingId=true) we skip this final
        // isValid() call because the ID is legitimately 0 at this stage and
        // isValid() requires id > 0.
        if (!$ignoreMissingId && !$position->isValid()) {
            return 'position is not valid';
        }

        return '';
    }

    /**
     * Build a Position object by merging imported row data over the existing
     * position's fields. Blank cells in the spreadsheet preserve the existing
     * value (so partial-column imports don't wipe data).
     *
     * For new positions, $existing is null and $newLongName supplies the title;
     * blank fields fall back to the Position defaults from the factory
     * (minimumSobriety=6, termYears=1, empty strings).
     *
     * @param int           $id          Post ID
     * @param array<string,string> $rowData Imported row data
     * @param Position|null $existing    Existing position, or null when creating
     * @param string        $newLongName Title for a new position (ignored when $existing is set)
     */
    private function buildMergedPosition(
        int $id,
        array $rowData,
        ?Position $existing,
        string $newLongName = ''
    ): Position {
        $rawMinSobriety = trim($rowData['minimum_sobriety']);
        $rawTermYears   = trim($rowData['term_years']);
        $rawEmail       = trim($rowData['email']);
        $rawShortDesc   = trim($rowData['short_description']);
        $rawSummary     = trim($rowData['summary']);
        $rawName        = trim($rowData['position_name']);

        $minimumSobriety = ctype_digit($rawMinSobriety)
            ? (int) $rawMinSobriety
            : ($existing ? $existing->getMinimumSobriety() : 6);

        $termYears = ctype_digit($rawTermYears)
            ? (int) $rawTermYears
            : ($existing ? $existing->getTermYears() : 1);

        $email = $rawEmail !== ''
            ? $rawEmail
            : ($existing ? $existing->getEmail() : '');

        $longName = $rawName !== ''
            ? $rawName
            : ($existing ? $existing->getLongName() : $newLongName);

        $shortDescription = $rawShortDesc !== ''
            ? $rawShortDesc
            : ($existing ? $existing->getShortDescription() : '');

        $summary = $rawSummary !== ''
            ? $rawSummary
            : ($existing ? $existing->getSummary() : '');

        return $this->positionFactory->createNew(
            id: $id,
            minimumSobriety: $minimumSobriety,
            termYears: $termYears,
            email: $email,
            longName: $longName,
            shortDescription: $shortDescription,
            summary: $summary
        );
    }

    /**
     * Save a position via the repository, capturing any PHP errors or exceptions.
     *
     * Mirrors MemberImporter::saveMember(): installs a non-suppressing error
     * handler so warnings/notices are captured for the import result while
     * still propagating to other handlers (Sentinel, Xdebug, PHP default).
     *
     * @param Position $position The position to save
     * @param string $errorMessage Populated with the error/exception message on failure
     * @return bool Whether the save succeeded
     */
    private function savePosition(Position $position, string &$errorMessage = ''): bool
    {
        $capturedErrors = [];

        set_error_handler(function (int $errno, string $errstr) use (&$capturedErrors): bool {
            $capturedErrors[] = $errstr;
            return false;
        });

        try {
            $saved = $this->positionRepository->save($position);
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            return false;
        } finally {
            restore_error_handler();
        }

        if (!$saved && !empty($capturedErrors)) {
            $errorMessage = implode('; ', $capturedErrors);
        }

        return $saved;
    }

    /**
     * Create a WordPress post for a new position.
     *
     * @param string $title The post title (position name)
     * @param string $errorMessage Populated with the error message if creation fails
     * @return int The new post ID, or 0 on failure
     */
    private function createPositionPost(string $title, string &$errorMessage = ''): int
    {
        $postId = wp_insert_post([
            'post_type'   => 'intergroup-position',
            'post_status' => 'publish',
            'post_title'  => $title,
        ], true);

        if (is_wp_error($postId)) {
            $errorMessage = $postId->get_error_message();
            \Reconcile\Plugin::logError('Reconcile: wp_insert_post failed – ' . $errorMessage);
            return 0;
        }

        return (int) $postId;
    }
}