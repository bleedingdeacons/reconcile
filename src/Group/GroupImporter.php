<?php

declare(strict_types=1);

namespace Reconcile\Group;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Reconcile\Core\OperationResult;
use Reconcile\Core\SpreadsheetReader;
use RuntimeException;
use Scrutiny\Audit\Interfaces\AuditLoggerInterface;
use Unity\Contacts\Interfaces\ContactFactory;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupFactory;
use Unity\Groups\Interfaces\GroupRepository;

/**
 * Group Importer
 *
 * Orchestrates the import of group data from a spreadsheet file:
 *  1. Reads the file (CSV or XLSX)
 *  2. Maps column headers to Group properties via GroupColumnMapper
 *  3. Validates required fields (either Group ID or Group Name must be populated)
 *  4. Resolves the target group:
 *     - If Group ID is provided, looks up by ID. If Group Name is also provided,
 *       updates the group title.
 *     - If only Group Name is provided (no ID column or ID is empty), looks up by name.
 *       If the name does not match an existing group, a new group is created.
 *  5. Builds contact arrays from up to 3 contact column sets
 *  6. Creates or updates groups through the Unity GroupRepository
 *
 * Rows that cannot be imported are skipped with a "Skipped – [reason]" warning.
 *
 * Returns an OperationResult with counts and any warnings/errors.
 */
class GroupImporter
{
    private ?GroupRepository $groupRepository;
    private ?GroupFactory $groupFactory;
    private ?ContactFactory $contactFactory;
    private AuditLoggerInterface $auditLogger;
    private GroupColumnMapper $columnMapper;
    private SpreadsheetReader $reader;
    private GroupLookup $groupLookup;

    public function __construct(
        ?GroupRepository $groupRepository,
        ?GroupFactory $groupFactory,
        ?ContactFactory $contactFactory,
        AuditLoggerInterface $auditLogger
    ) {
        $this->groupRepository = $groupRepository;
        $this->groupFactory = $groupFactory;
        $this->contactFactory = $contactFactory;
        $this->auditLogger = $auditLogger;
        $this->columnMapper = new GroupColumnMapper();
        $this->reader = new SpreadsheetReader();
        $this->groupLookup = new GroupLookup($groupRepository);
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

        \Reconcile\Plugin::logDebug('Reconcile GroupImporter: Starting import from ' . $filePath . ' (dry_run=' . ($dryRun ? 'true' : 'false') . ').');

        if ($this->groupRepository === null) {
            \Reconcile\Plugin::logError('Reconcile GroupImporter: GroupRepository is null.');
            $result->addError('Unity GroupRepository is not available. Is Unity fully configured?');
            return $result;
        }

        if ($this->groupFactory === null) {
            \Reconcile\Plugin::logError('Reconcile GroupImporter: GroupFactory is null.');
            $result->addError('Unity GroupFactory is not available. Is Unity fully configured?');
            return $result;
        }

        // 1. Read spreadsheet
        try {
            $data = $this->reader->read($filePath);
        } catch (RuntimeException $e) {
            \Reconcile\Plugin::logError('Reconcile GroupImporter: Failed to read spreadsheet: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $result->addError($e->getMessage());
            return $result;
        }

        $headers = $data['headers'];
        $rows = $data['rows'];

        \Reconcile\Plugin::logDebug('Reconcile GroupImporter: Read ' . count($rows) . ' data row(s) with headers: ' . implode(', ', $headers));

        // 2. Map columns
        $mapping = $this->columnMapper->mapHeaders($headers);

        \Reconcile\Plugin::logDebug('Reconcile GroupImporter: Column mapping — ' . json_encode($mapping));

        $missing = $this->columnMapper->validateMapping($mapping);

        if (!empty($missing)) {
            $labels = GroupColumnMapper::getPropertyLabels();
            $missingLabels = array_map(fn($p) => $labels[$p] ?? $p, $missing);
            $errorMsg = 'Missing required columns: ' . implode(', ', $missingLabels) . '. '
                . 'Please ensure your spreadsheet has headers matching: '
                . implode(', ', array_map(fn($p) => $labels[$p] ?? $p, array_keys($labels))) . '.';
            \Reconcile\Plugin::logError('Reconcile GroupImporter: ' . $errorMsg);
            $result->addError($errorMsg);
            return $result;
        }

        $result->setTotalRows(count($rows));

        // 3. Process each row
        foreach ($rows as $rowIndex => $row) {
            $lineNumber = $rowIndex + 2; // +1 for 0-index, +1 for header row

            try {
                $rowData = $this->extractRowData($row, $mapping);

                $rawGroupId = trim($rowData['group_id']);
                $rawGroupName = trim($rowData['group_name']);

                // Validate: at least one of group ID or group name must be provided
                if ($rawGroupId === '' && $rawGroupName === '') {
                    $result->skipRow($lineNumber, 'Both Group ID and Group Name are empty. At least one is required.', $this->buildRowDetails($rowData));
                    continue;
                }

                $existingGroup = null;

                if ($rawGroupId !== '') {
                    // ID was supplied — use it to find the group
                    if (!ctype_digit($rawGroupId)) {
                        $result->skipRow(
                            $lineNumber,
                            "Group ID \"{$rawGroupId}\" is not a valid numeric ID.",
                            $this->buildRowDetails($rowData)
                        );
                        continue;
                    }

                    $groupId = (int) $rawGroupId;
                    $existingGroup = $this->findExistingGroup($groupId);

                    if ($existingGroup === null) {
                        $result->skipRow(
                            $lineNumber,
                            "Group ID {$groupId} does not match an existing group.",
                            $this->buildRowDetails($rowData)
                        );
                        continue;
                    }
                } else {
                    // No ID supplied — use group name to find the group
                    $resolvedId = $this->groupLookup->resolve($rawGroupName);

                    if ($resolvedId !== 0) {
                        $existingGroup = $this->findExistingGroup($resolvedId);

                        if ($existingGroup === null) {
                            $result->skipRow(
                                $lineNumber,
                                "Group Name \"{$rawGroupName}\" resolved to ID {$resolvedId} but the group could not be loaded.",
                                $this->buildRowDetails($rowData)
                            );
                            continue;
                        }
                    }
                    // If resolvedId === 0, existingGroup stays null — a new group will be created
                }

                // Build contacts array
                $contacts = $this->buildContacts($rowData);

                // Full context for reporting
                $fullDetails = $this->buildRowDetails(
                    $rowData,
                    $existingGroup ? $existingGroup->getId() : null
                );

                if ($dryRun) {
                    if ($existingGroup) {
                        $result->incrementUpdated();
                    } else {
                        $result->incrementCreated();
                    }
                    continue;
                }

                // Persist
                if ($existingGroup) {
                    // Update existing group
                    $saveError = '';
                    $saved = $this->updateGroup(
                        $existingGroup,
                        $rowData,
                        $contacts,
                        $saveError
                    );
                    if ($saved) {
                        $result->incrementUpdated();
                    } else {
                        $resolvedId = $existingGroup->getId();
                        $groupLabel = !empty($rowData['group_name'])
                            ? "\"{$rowData['group_name']}\" (ID: {$resolvedId})"
                            : "ID: {$resolvedId}";
                        $reason = "Failed to update group {$groupLabel}.";
                        if ($saveError !== '') {
                            $reason .= " Error: {$saveError}";
                        }
                        $result->skipRow($lineNumber, $reason, $fullDetails);
                    }
                } else {
                    // Create new group
                    $wpError = '';
                    $postId = $this->createGroupPost($rawGroupName, $wpError);

                    if ($postId === 0) {
                        $reason = "Failed to create WordPress post for \"{$rawGroupName}\".";
                        if ($wpError !== '') {
                            $reason .= " wp_insert_post error: {$wpError}";
                        }
                        $result->skipRow($lineNumber, $reason, $fullDetails);
                        continue;
                    }

                    $saveError = '';
                    $saved = $this->saveNewGroup($postId, $rowData, $contacts, $saveError);

                    if ($saved) {
                        $result->incrementCreated();
                    } else {
                        $fullDetails['Post ID'] = (string) $postId;
                        $reason = "Post created (#{$postId}) but fields failed to save"
                            . " for \"{$rawGroupName}\".";
                        if ($saveError !== '') {
                            $reason .= " Error: {$saveError}";
                        }
                        $result->skipRow($lineNumber, $reason, $fullDetails);
                    }
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
            $this->auditLogger->log(
                AuditLoggerInterface::ACTION_IMPORT,
                AuditLoggerInterface::ENTITY_GROUP,
                -1,
                'Group Contacts',
                $imported . ' group(s) imported from spreadsheet.'
            );
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
            'email'         => '',
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
     * Build a details array for a skipped row showing raw CSV values and resolved data.
     *
     * @param array<string, string> $rowData Raw extracted row data
     * @param int|null $existingGroupId Existing group post ID if updating
     * @return array<string, string>
     */
    private function buildRowDetails(
        array $rowData,
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
        if ($existingGroupId !== null) {
            $details['Existing Group ID'] = (string) $existingGroupId;
        }

        return $details;
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
            \Reconcile\Plugin::logError('Reconcile: Error finding group by ID: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return null;
        }
    }

    /**
     * Create a WordPress post for a new group.
     *
     * @param string $title The post title (group name)
     * @param string $errorMessage Populated with the error message if creation fails
     * @return int The new post ID, or 0 on failure
     */
    private function createGroupPost(string $title, string &$errorMessage = ''): int
    {
        $postId = wp_insert_post([
            'post_type'   => 'intergroup-group',
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

    /**
     * Save meta fields for a newly created group post.
     *
     * @param int $postId The WordPress post ID
     * @param array<string, string> $rowData The imported row data
     * @param array<int, array{name: string, email: string, phone: string}> $contacts
     * @param string $errorMessage Populated with error message on failure
     * @return bool Whether the save succeeded
     */
    private function saveNewGroup(
        int $postId,
        array $rowData,
        array $contacts,
        string &$errorMessage = ''
    ): bool {
        $capturedError = '';

        set_error_handler(function (int $errno, string $errstr) use (&$capturedError): bool {
            $capturedError = $errstr;
            return true;
        });

        try {
            $this->saveMetaFields($postId, $rowData, $contacts);
            return true;
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            return false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Update an existing group with imported data.
     *
     * @param Group $existing The existing group
     * @param array<string, string> $rowData The imported row data
     * @param array<int, array{name: string, email: string, phone: string}> $contacts
     * @param string $errorMessage Populated with error message on failure
     * @return bool Whether the update succeeded
     */
    private function updateGroup(
        Group $existing,
        array $rowData,
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

            $this->saveMetaFields($postId, $rowData, $contacts);

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
     * @param array<int, array{name: string, email: string, phone: string}> $contacts
     */
    private function saveMetaFields(
        int $postId,
        array $rowData,
        array $contacts
    ): void {
        update_post_meta($postId, 'email', $rowData['email']);

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