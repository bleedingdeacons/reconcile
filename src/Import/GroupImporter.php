<?php

declare(strict_types=1);

namespace Reconcile\Import;

use Unity\Contacts\Interfaces\ContactFactory;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupFactory;
use Unity\Groups\Interfaces\GroupRepository;

use RuntimeException;

/**
 * Group Importer
 *
 * Orchestrates the import of group data from a spreadsheet file:
 *  1. Reads the file (CSV or XLSX)
 *  2. Maps column headers to Group properties via GroupColumnMapper
 *  3. Validates required fields (Group ID must be populated)
 *  4. Group Name is optional — if provided the group title is updated
 *  5. Parses Group Email Active from common truthy/falsy strings
 *  6. Builds contact arrays from up to 3 contact column sets
 *  7. Updates groups through the Unity GroupRepository
 *
 * Rows that cannot be imported are skipped with a "Skipped – [reason]" warning.
 *
 * Returns an ImportResult with counts and any warnings/errors.
 */
class GroupImporter
{
    /**
     * Values recognised as boolean true when parsing the Group Email Active column.
     */
    private const TRUTHY_VALUES = ['yes', 'y', 'true'];

    private ?GroupRepository $groupRepository;
    private ?GroupFactory $groupFactory;
    private ?ContactFactory $contactFactory;
    private GroupColumnMapper $columnMapper;
    private SpreadsheetReader $reader;

    public function __construct(
        ?GroupRepository $groupRepository,
        ?GroupFactory $groupFactory,
        ?ContactFactory $contactFactory
    ) {
        $this->groupRepository = $groupRepository;
        $this->groupFactory = $groupFactory;
        $this->contactFactory = $contactFactory;
        $this->columnMapper = new GroupColumnMapper();
        $this->reader = new SpreadsheetReader();
    }

    /**
     * Run the import from a file path.
     *
     * @param string $filePath Absolute path to the uploaded spreadsheet
     * @param bool $dryRun If true, validate only – do not persist anything
     * @return ImportResult
     */
    public function import(string $filePath, bool $dryRun = false): ImportResult
    {
        $result = new ImportResult();

        error_log('Reconcile GroupImporter: Starting import from ' . $filePath . ' (dry_run=' . ($dryRun ? 'true' : 'false') . ').');

        if ($this->groupRepository === null) {
            error_log('Reconcile GroupImporter: GroupRepository is null.');
            $result->addError('Unity GroupRepository is not available. Is Unity fully configured?');
            return $result;
        }

        if ($this->groupFactory === null) {
            error_log('Reconcile GroupImporter: GroupFactory is null.');
            $result->addError('Unity GroupFactory is not available. Is Unity fully configured?');
            return $result;
        }

        // 1. Read spreadsheet
        try {
            $data = $this->reader->read($filePath);
        } catch (RuntimeException $e) {
            error_log('Reconcile GroupImporter: Failed to read spreadsheet — ' . $e->getMessage());
            $result->addError($e->getMessage());
            return $result;
        }

        $headers = $data['headers'];
        $rows = $data['rows'];

        error_log('Reconcile GroupImporter: Read ' . count($rows) . ' data row(s) with headers: ' . implode(', ', $headers));

        // 2. Map columns
        $mapping = $this->columnMapper->mapHeaders($headers);

        error_log('Reconcile GroupImporter: Column mapping — ' . json_encode($mapping));

        $missing = $this->columnMapper->validateMapping($mapping);

        if (!empty($missing)) {
            $labels = GroupColumnMapper::getPropertyLabels();
            $missingLabels = array_map(fn($p) => $labels[$p] ?? $p, $missing);
            $errorMsg = 'Missing required columns: ' . implode(', ', $missingLabels) . '. '
                . 'Please ensure your spreadsheet has headers matching: '
                . implode(', ', array_map(fn($p) => $labels[$p] ?? $p, array_keys($labels))) . '.';
            error_log('Reconcile GroupImporter: ' . $errorMsg);
            $result->addError($errorMsg);
            return $result;
        }

        $result->setTotalRows(count($rows));

        // 3. Process each row
        foreach ($rows as $rowIndex => $row) {
            $lineNumber = $rowIndex + 2; // +1 for 0-index, +1 for header row

            try {
                $rowData = $this->extractRowData($row, $mapping);

                // Validate: group ID is required and must be numeric
                $rawGroupId = trim($rowData['group_id']);
                if ($rawGroupId === '') {
                    $result->skipRow($lineNumber, 'Group ID is empty.', $this->buildRowDetails($rowData));
                    continue;
                }

                if (!ctype_digit($rawGroupId)) {
                    $result->skipRow(
                        $lineNumber,
                        "Group ID \"{$rawGroupId}\" is not a valid numeric ID.",
                        $this->buildRowDetails($rowData)
                    );
                    continue;
                }

                $groupId = (int) $rawGroupId;

                // Parse Group Email Active boolean
                $groupEmailActive = $this->parseBool($rowData['group_email_active']);

                // Build contacts array
                $contacts = $this->buildContacts($rowData);

                // Check for existing group by ID
                $existingGroup = $this->findExistingGroup($groupId);
                if ($existingGroup === null) {
                    $result->skipRow(
                        $lineNumber,
                        "Group ID {$groupId} does not match an existing group.",
                        $this->buildRowDetails($rowData)
                    );
                    continue;
                }

                // Full context for reporting
                $fullDetails = $this->buildRowDetails(
                    $rowData,
                    $groupEmailActive,
                    $existingGroup->getId()
                );

                if ($dryRun) {
                    $result->incrementUpdated();
                    continue;
                }

                // Persist update
                $saveError = '';
                $saved = $this->updateGroup(
                    $existingGroup,
                    $rowData,
                    $groupEmailActive,
                    $contacts,
                    $saveError
                );
                if ($saved) {
                    $result->incrementUpdated();
                } else {
                    $groupLabel = !empty($rowData['group_name'])
                        ? "\"{$rowData['group_name']}\" (ID: {$groupId})"
                        : "ID: {$groupId}";
                    $reason = "Failed to update group {$groupLabel}.";
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
            'group_id'            => '',
            'group_name'          => '',
            'group_email'         => '',
            'group_email_active'  => '',
            'contact_1_name'      => '',
            'contact_1_email'     => '',
            'contact_1_phone'     => '',
            'contact_2_name'      => '',
            'contact_2_email'     => '',
            'contact_2_phone'     => '',
            'contact_3_name'      => '',
            'contact_3_email'     => '',
            'contact_3_phone'     => '',
        ];

        foreach ($mapping as $colIndex => $property) {
            $data[$property] = $row[$colIndex] ?? '';
        }

        return $data;
    }

    /**
     * Build an array of contact data from the row.
     *
     * Each contact is an associative array with 'name', 'email', 'phone'.
     * Only contacts where at least one field is non-empty are included.
     *
     * @param array<string, string> $rowData
     * @return array<int, array{name: string, email: string, phone: string}>
     */
    private function buildContacts(array $rowData): array
    {
        $contacts = [];

        for ($i = 1; $i <= 3; $i++) {
            $name  = trim($rowData["contact_{$i}_name"] ?? '');
            $email = trim($rowData["contact_{$i}_email"] ?? '');
            $phone = trim($rowData["contact_{$i}_phone"] ?? '');

            if ($name !== '' || $email !== '' || $phone !== '') {
                $contacts[] = [
                    'name'  => $name,
                    'email' => $email,
                    'phone' => $phone,
                ];
            }
        }

        return $contacts;
    }

    /**
     * Get the list of string values recognised as boolean true.
     *
     * @return string[]
     */
    public static function getTruthyValues(): array
    {
        return self::TRUTHY_VALUES;
    }

    /**
     * Build a details array for a skipped row showing raw CSV values and resolved data.
     *
     * @param array<string, string> $rowData Raw extracted row data
     * @param bool|null $groupEmailActive Parsed boolean (null if not yet parsed)
     * @param int|null $existingGroupId Existing group post ID if updating
     * @return array<string, string>
     */
    private function buildRowDetails(
        array $rowData,
        ?bool $groupEmailActive = null,
        ?int $existingGroupId = null
    ): array {
        $labels = GroupColumnMapper::getPropertyLabels();
        $details = [];

        // Raw CSV values
        foreach ($rowData as $property => $value) {
            $label = $labels[$property] ?? $property;
            $details[$label] = $value !== '' ? $value : '(empty)';
        }

        // Resolved values (only include when available)
        if ($groupEmailActive !== null) {
            $details['Group Email Active → Parsed'] = $groupEmailActive ? 'true' : 'false';
        }

        if ($existingGroupId !== null) {
            $details['Existing Group ID'] = (string) $existingGroupId;
        }

        return $details;
    }

    /**
     * Parse a string value as a boolean.
     *
     * Accepts common truthy values defined in TRUTHY_VALUES.
     * Everything else (including empty string) is false.
     */
    private function parseBool(string $value): bool
    {
        $normalised = mb_strtolower(trim($value));

        return in_array($normalised, self::TRUTHY_VALUES, true);
    }

    /**
     * Try to find an existing group by its post ID.
     */
    private function findExistingGroup(int $groupId): ?Group
    {
        if ($this->groupRepository === null) {
            return null;
        }

        try {
            return $this->groupRepository->findById($groupId);
        } catch (\Exception $e) {
            error_log('Reconcile: Error finding group by ID – ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update an existing group with imported data.
     *
     * @param Group $existing The existing group
     * @param array<string, string> $rowData The imported row data
     * @param bool $groupEmailActive Parsed email active status
     * @param array<int, array{name: string, email: string, phone: string}> $contacts
     * @param string $errorMessage Populated with error message on failure
     * @return bool Whether the update succeeded
     */
    private function updateGroup(
        Group $existing,
        array $rowData,
        bool $groupEmailActive,
        array $contacts,
        string &$errorMessage = ''
    ): bool {
        $postId = $existing->getId();

        $capturedError = '';

        set_error_handler(function (int $errno, string $errstr) use (&$capturedError): bool {
            $capturedError = $errstr;
            return true;
        });

        try {
            // Update post title only if a group name was provided
            $groupName = trim($rowData['group_name']);
            if ($groupName !== '') {
                $postData = [
                    'ID'          => $postId,
                    'post_title'  => $groupName,
                ];

                $result = wp_update_post($postData, true);

                if (is_wp_error($result)) {
                    $errorMessage = $result->get_error_message();
                    return false;
                }
            }

            $this->saveMetaFields($postId, $rowData, $groupEmailActive, $contacts);

            return true;
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            return false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Save meta fields for a group post.
     *
     * @param int $postId The WordPress post ID
     * @param array<string, string> $rowData The imported row data
     * @param bool $groupEmailActive Parsed email active status
     * @param array<int, array{name: string, email: string, phone: string}> $contacts
     */
    private function saveMetaFields(
        int $postId,
        array $rowData,
        bool $groupEmailActive,
        array $contacts
    ): void {
        update_post_meta($postId, 'group_email', $rowData['group_email']);
        update_post_meta($postId, 'group_email_active', $groupEmailActive ? '1' : '0');

        // Save contacts (clear all 3 slots then fill)
        for ($i = 1; $i <= 3; $i++) {
            $contactIndex = $i - 1;
            if (isset($contacts[$contactIndex])) {
                update_post_meta($postId, "contact_{$i}_name", $contacts[$contactIndex]['name']);
                update_post_meta($postId, "contact_{$i}_email", $contacts[$contactIndex]['email']);
                update_post_meta($postId, "contact_{$i}_phone", $contacts[$contactIndex]['phone']);
            } else {
                update_post_meta($postId, "contact_{$i}_name", '');
                update_post_meta($postId, "contact_{$i}_email", '');
                update_post_meta($postId, "contact_{$i}_phone", '');
            }
        }
    }
}
