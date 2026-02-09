<?php

declare(strict_types=1);

namespace Reconcile\Import;

use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

use RuntimeException;

/**
 * Member Importer
 *
 * Orchestrates the import of member data from a spreadsheet file:
 *  1. Reads the file (CSV or XLSX)
 *  2. Maps column headers to Member properties
 *  3. Resolves Home Group name strings to post IDs via GroupLookup
 *  4. Resolves Intergroup Position name strings to post IDs via PositionLookup
 *  5. Parses and validates Position Rotation dates (accepted: yyyy/MM/dd, dd/MM/yyyy, dd/MM/yy)
 *  6. Parses GSR status from common truthy/falsy strings
 *  7. Creates or updates members through the Unity MemberRepository
 *
 * Rows that cannot be imported are skipped with a "Skipped – [reason]" warning.
 *
 * Returns an ImportResult with counts and any warnings/errors.
 */
class MemberImporter
{
    /**
     * Values recognised as boolean true when parsing the GSR Status column.
     */
    private const TRUTHY_VALUES = ['yes', 'y', 'true', '1', 'gsr'];

    /**
     * Accepted date formats for Position Rotation, shown in help text.
     *
     * Separators /, - and . are all accepted for each format.
     */
    private const ACCEPTED_DATE_FORMATS = [
        'yyyy/MM/dd',
        'dd/MM/yyyy',
        'dd/MM/yy',
    ];

    private ?MemberRepository $memberRepository;
    private GroupLookup $groupLookup;
    private PositionLookup $positionLookup;
    private ColumnMapper $columnMapper;
    private SpreadsheetReader $reader;

    public function __construct(
        ?MemberRepository $memberRepository,
        GroupLookup $groupLookup,
        PositionLookup $positionLookup
    ) {
        $this->memberRepository = $memberRepository;
        $this->groupLookup = $groupLookup;
        $this->positionLookup = $positionLookup;
        $this->columnMapper = new ColumnMapper();
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

        if ($this->memberRepository === null) {
            $result->addError('Unity MemberRepository is not available. Is Unity fully configured?');
            return $result;
        }

        // 1. Read spreadsheet
        try {
            $data = $this->reader->read($filePath);
        } catch (RuntimeException $e) {
            $result->addError($e->getMessage());
            return $result;
        }

        $headers = $data['headers'];
        $rows = $data['rows'];

        // 2. Map columns
        $mapping = $this->columnMapper->mapHeaders($headers);
        $missing = $this->columnMapper->validateMapping($mapping);

        if (!empty($missing)) {
            $labels = ColumnMapper::getPropertyLabels();
            $missingLabels = array_map(fn($p) => $labels[$p] ?? $p, $missing);
            $result->addError(
                'Missing required columns: ' . implode(', ', $missingLabels) . '. '
                . 'Please ensure your spreadsheet has headers matching: '
                . implode(', ', array_map(fn($p) => $labels[$p] ?? $p, array_keys($labels))) . '.'
            );
            return $result;
        }

        $result->setTotalRows(count($rows));

        // 3. Reset lookups for fresh reporting
        $this->groupLookup->resetUnresolved();
        $this->positionLookup->resetUnresolved();

        // 4. Process each row
        foreach ($rows as $rowIndex => $row) {
            $lineNumber = $rowIndex + 2; // +1 for 0-index, +1 for header row

            try {
                $rowData = $this->extractRowData($row, $mapping);

                // Validate: anonymous name is required
                if (empty($rowData['anonymous_name'])) {
                    $result->skipRow($lineNumber, 'Anonymous Name is empty.', $this->buildRowDetails($rowData));
                    continue;
                }

                // Resolve home group string to post ID
                $homeGroupId = $this->groupLookup->resolve($rowData['home_group']);

                if (!empty($rowData['home_group']) && $homeGroupId === 0) {
                    $result->addWarning(
                        "Row {$lineNumber}: Home Group \"{$rowData['home_group']}\" "
                        . "could not be matched to an existing group. Set to 0."
                    );
                }

                // Resolve intergroup position string to post ID
                $intergroupPositionId = $this->positionLookup->resolve($rowData['intergroup_position']);

                if (!empty($rowData['intergroup_position']) && $intergroupPositionId === 0) {
                    $result->addWarning(
                        "Row {$lineNumber}: Intergroup Position \"{$rowData['intergroup_position']}\" "
                        . "could not be matched to an existing position. Set to 0."
                    );
                }

                // Parse and validate position rotation date
                $rawRotation = trim($rowData['intergroup_position_rotation']);
                $positionRotation = '';

                if ($rawRotation !== '') {
                    $parsed = $this->parseDate($rawRotation);
                    if ($parsed === null) {
                        $result->skipRow(
                            $lineNumber,
                            "Intergroup Position Rotation \"{$rawRotation}\" is not a recognised date format. "
                            . "Accepted formats: " . implode(', ', self::ACCEPTED_DATE_FORMATS) . ".",
                            $this->buildRowDetails($rowData, $homeGroupId, $intergroupPositionId)
                        );
                        continue;
                    }
                    $positionRotation = $parsed;
                } elseif (!empty($rowData['intergroup_position'])) {
                    $result->skipRow(
                        $lineNumber,
                        "Intergroup Position is set to \"{$rowData['intergroup_position']}\" "
                        . "but Intergroup Position Rotation is empty.",
                        $this->buildRowDetails($rowData, $homeGroupId, $intergroupPositionId)
                    );
                    continue;
                }

                // Parse GSR boolean
                $isGSR = $this->parseBool($rowData['is_gsr']);

                // Check for existing member by anonymous name
                $existingMember = $this->findExistingMember($rowData['anonymous_name']);

                // Build Member object
                $memberId = $existingMember ? $existingMember->getId() : 0;
                $member = $this->buildMember(
                    $memberId,
                    $rowData,
                    $homeGroupId,
                    $intergroupPositionId,
                    $positionRotation,
                    $isGSR,
                    $existingMember
                );

                // Full context for persistence failures
                $fullDetails = $this->buildRowDetails(
                    $rowData,
                    $homeGroupId,
                    $intergroupPositionId,
                    $positionRotation,
                    $isGSR,
                    $existingMember ? $existingMember->getId() : null
                );

                if ($dryRun) {
                    if ($existingMember) {
                        $result->incrementUpdated();
                    } else {
                        $result->incrementCreated();
                    }
                    continue;
                }

                // Persist
                if ($existingMember) {
                    $saveError = '';
                    $saved = $this->saveMember($member, $saveError);
                    if ($saved) {
                        $result->incrementUpdated();
                    } else {
                        $reason = "Failed to update member \"{$rowData['anonymous_name']}\".";
                        if ($saveError !== '') {
                            $reason .= " MemberRepository->save() error: {$saveError}";
                        } else {
                            $reason .= " MemberRepository->save() returned false with no error message.";
                        }
                        $fullDetails['Existing Member ID'] = (string) $existingMember->getId();
                        $result->skipRow($lineNumber, $reason, $fullDetails);
                    }
                } else {
                    $wpError = '';
                    $postId = $this->createMemberPost($rowData['anonymous_name'], $wpError);

                    if ($postId === 0) {
                        $reason = "Failed to create WordPress post for \"{$rowData['anonymous_name']}\".";
                        if ($wpError !== '') {
                            $reason .= " wp_insert_post error: {$wpError}";
                        }
                        $result->skipRow($lineNumber, $reason, $fullDetails);
                        continue;
                    }

                    $member = $this->buildMember(
                        $postId,
                        $rowData,
                        $homeGroupId,
                        $intergroupPositionId,
                        $positionRotation,
                        $isGSR
                    );

                    $saveError = '';
                    $saved = $this->saveMember($member, $saveError);

                    if ($saved) {
                        $result->incrementCreated();
                    } else {
                        $fullDetails['Post ID'] = (string) $postId;
                        $reason = "Post created (#{$postId}) but fields failed to save"
                            . " for \"{$rowData['anonymous_name']}\".";
                        if ($saveError !== '') {
                            $reason .= " MemberRepository->save() error: {$saveError}";
                        } else {
                            $reason .= " MemberRepository->save() returned false with no error message.";
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

        // Append unresolved group warnings
        $unresolvedGroups = $this->groupLookup->getUnresolvedNames();
        if (!empty($unresolvedGroups)) {
            $result->addWarning(
                'The following Home Group names could not be matched to existing groups: '
                . implode(', ', array_map(fn($n) => "\"{$n}\"", $unresolvedGroups))
            );
        }

        // Append unresolved position warnings
        $unresolvedPositions = $this->positionLookup->getUnresolvedNames();
        if (!empty($unresolvedPositions)) {
            $result->addWarning(
                'The following Intergroup Position names could not be matched to existing positions: '
                . implode(', ', array_map(fn($n) => "\"{$n}\"", $unresolvedPositions))
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
            'anonymous_name'                => '',
            'home_group'                    => '',
            'personal_email'                => '',
            'mobile_number'                 => '',
            'is_gsr'                        => '',
            'intergroup_position'           => '',
            'intergroup_position_rotation'  => '',
        ];

        foreach ($mapping as $colIndex => $property) {
            $data[$property] = $row[$colIndex] ?? '';
        }

        return $data;
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
     * @param int|null $homeGroupId Resolved home group post ID (null if not yet resolved)
     * @param int|null $intergroupPositionId Resolved position post ID (null if not yet resolved)
     * @param string|null $positionRotation Parsed rotation date (null if not yet parsed)
     * @param bool|null $isGSR Parsed GSR boolean (null if not yet parsed)
     * @param int|null $existingMemberId Existing member post ID if updating (null if not checked yet)
     * @return array<string, string>
     */
    private function buildRowDetails(
        array $rowData,
        ?int $homeGroupId = null,
        ?int $intergroupPositionId = null,
        ?string $positionRotation = null,
        ?bool $isGSR = null,
        ?int $existingMemberId = null
    ): array {
        $labels = ColumnMapper::getPropertyLabels();
        $details = [];

        // Raw CSV values
        foreach ($rowData as $property => $value) {
            $label = $labels[$property] ?? $property;
            $details[$label] = $value !== '' ? $value : '(empty)';
        }

        // Resolved values (only include when available)
        if ($homeGroupId !== null) {
            $details['Home Group → Post ID'] = $homeGroupId === 0 && !empty($rowData['home_group'])
                ? '0 (not matched)'
                : (string) $homeGroupId;
        }

        if ($intergroupPositionId !== null) {
            $details['Intergroup Position → Post ID'] = $intergroupPositionId === 0 && !empty($rowData['intergroup_position'])
                ? '0 (not matched)'
                : (string) $intergroupPositionId;
        }

        if ($positionRotation !== null) {
            $details['Rotation → Parsed'] = $positionRotation !== '' ? $positionRotation : '(empty)';
        }

        if ($isGSR !== null) {
            $details['GSR → Parsed'] = $isGSR ? 'true' : 'false';
        }

        if ($existingMemberId !== null) {
            $details['Existing Member ID'] = (string) $existingMemberId;
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
     * Parse a date string into ISO format (Y-m-d).
     *
     * Accepted formats (with /, - or . separators):
     *  - yyyy/MM/dd  (e.g. 2025/01/15, 2025-01-15, 2025.01.15)
     *  - dd/MM/yyyy  (e.g. 15/01/2025)
     *  - dd/MM/yy    (e.g. 15/01/25)
     *
     * @return string|null ISO date string (Y-m-d), or null if unrecognised
     */
    private function parseDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Normalise separators to /
        $normalised = str_replace(['-', '.'], '/', $value);

        $parts = explode('/', $normalised);
        if (count($parts) !== 3) {
            return null;
        }

        [$a, $b, $c] = $parts;

        // All segments must be numeric
        if (!ctype_digit($a) || !ctype_digit($b) || !ctype_digit($c)) {
            return null;
        }

        $year = 0;
        $month = 0;
        $day = 0;

        if (strlen($a) === 4) {
            // yyyy/MM/dd
            $year  = (int) $a;
            $month = (int) $b;
            $day   = (int) $c;
        } elseif (strlen($c) === 4) {
            // dd/MM/yyyy
            $day   = (int) $a;
            $month = (int) $b;
            $year  = (int) $c;
        } elseif (strlen($c) === 2 && strlen($a) <= 2) {
            // dd/MM/yy
            $day   = (int) $a;
            $month = (int) $b;
            $year  = 2000 + (int) $c;
        } else {
            return null;
        }

        // Validate the date is real
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Get the accepted date format labels (for admin help text).
     *
     * @return string[]
     */
    public static function getAcceptedDateFormats(): array
    {
        return self::ACCEPTED_DATE_FORMATS;
    }

    /**
     * Try to find an existing member by anonymous name.
     */
    private function findExistingMember(string $anonymousName): ?Member
    {
        if ($this->memberRepository === null) {
            return null;
        }

        try {
            $members = $this->memberRepository->findAll([
                'meta_query' => [
                    [
                        'key'     => 'about-layout-group_anonymous-name',
                        'value'   => $anonymousName,
                        'compare' => '=',
                    ],
                ],
                'numberposts' => 1,
            ]);

            return !empty($members) ? $members[0] : null;
        } catch (\Exception $e) {
            error_log('Reconcile: Error finding member by name – ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Build a Member object with the imported data, preserving any existing
     * fields that are not part of this import.
     *
     * @param int $id Post ID (0 for new members)
     * @param array<string, string> $rowData
     * @param int $homeGroupId Resolved home group post ID
     * @param int $intergroupPositionId Resolved intergroup position post ID
     * @param string $positionRotation Position rotation value from spreadsheet
     * @param bool $isGSR Parsed GSR status
     * @param Member|null $existing Existing member for field preservation
     */
    private function buildMember(
        int $id,
        array $rowData,
        int $homeGroupId,
        int $intergroupPositionId,
        string $positionRotation,
        bool $isGSR,
        ?Member $existing = null
    ): Member {
        return new Member(
            id: $id,
            anonymousName: $rowData['anonymous_name'],
            privateName: $existing ? $existing->getPrivateName() : '',
            email: $existing ? $existing->getEmail() : '',
            showAnonymousName: $existing ? $existing->showAnonymousName() : false,
            showMemberProfile: $existing ? $existing->showMemberProfile() : false,
            anonymousProfile: $existing ? $existing->getAnonymousProfile() : '',
            intergroupPosition: $intergroupPositionId,
            intergroupPositionRotation: $positionRotation !== ''
                ? $positionRotation
                : ($existing ? $existing->getIntergroupPositionRotation() : ''),
            homeGroup: $homeGroupId,
            isGSR: $isGSR,
            meetingPO: $existing ? $existing->getMeetingPO() : null,
            personalEmail: $rowData['personal_email'],
            mobileNumber: $rowData['mobile_number'],
        );
    }

    /**
     * Create a new WordPress post for a member.
     *
     * @return int The new post ID, or 0 on failure
     */
    /**
     * Save a member via the repository, capturing any PHP errors or exceptions.
     *
     * @param Member $member The member to save
     * @param string $errorMessage Populated with the error/exception message on failure
     * @return bool Whether the save succeeded
     */
    private function saveMember(Member $member, string &$errorMessage = ''): bool
    {
        $capturedError = '';

        // Temporarily capture PHP warnings/notices that the repository might trigger
        set_error_handler(function (int $errno, string $errstr) use (&$capturedError): bool {
            $capturedError = $errstr;
            return true; // suppress the error from propagating
        });

        try {
            $saved = $this->memberRepository->save($member);
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            return false;
        } finally {
            restore_error_handler();
        }

        if (!$saved && $capturedError !== '') {
            $errorMessage = $capturedError;
        }

        return $saved;
    }

    /**
     * Create a WordPress post for a new member.
     *
     * @param string $title The post title (anonymous name)
     * @param string $errorMessage Populated with the error message if creation fails
     * @return int The new post ID, or 0 on failure
     */
    private function createMemberPost(string $title, string &$errorMessage = ''): int
    {
        $postId = wp_insert_post([
            'post_type'   => 'intergroup-member',
            'post_status' => 'publish',
            'post_title'  => $title,
        ], true);

        if (is_wp_error($postId)) {
            $errorMessage = $postId->get_error_message();
            error_log('Reconcile: wp_insert_post failed – ' . $errorMessage);
            return 0;
        }

        return (int) $postId;
    }
}
