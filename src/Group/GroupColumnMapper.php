<?php

declare(strict_types=1);

namespace Reconcile\Group;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Group Column Mapper
 *
 * Maps spreadsheet column headers to Group property keys.
 *
 * The spreadsheet headers are normalised (lowercased, trimmed, special chars removed)
 * and then matched against a known set of aliases for each property.
 *
 * Currently supported properties:
 *  - group_id       (identifies group by post ID — required if group_name not provided)
 *  - group_name     (identifies group by title, or updates title if group_id also provided)
 *  - email    (the group's dedicated email address)
 *  - contact_1_name, contact_1_email, contact_1_phone
 *  - contact_2_name, contact_2_email, contact_2_phone
 *  - contact_3_name, contact_3_email, contact_3_phone
 *
 * Either group_id or group_name (or both) must be present as a column header.
 */
class GroupColumnMapper
{
    /**
     * Canonical property name => list of accepted header aliases (all lowercase)
     *
     * @var array<string, string[]>
     */
    private const ALIASES = [
        'group_id' => [
            'group id',
            'group_id',
            'groupid',
        ],
        'group_name' => [
            'group name',
            'group_name',
            'groupname',
        ],
        'email' => [
            'group email',
            'email',
            'groupemail',
        ],
        'contact_1_name' => [
            'contact 1 name',
            'contact_1_name',
        ],
        'contact_1_email' => [
            'contact 1 email',
            'contact_1_email',
        ],
        'contact_1_phone' => [
            'contact 1 phone',
            'contact_1_phone',
        ],
        'contact_2_name' => [
            'contact 2 name',
            'contact_2_name',
        ],
        'contact_2_email' => [
            'contact 2 email',
            'contact_2_email',
        ],
        'contact_2_phone' => [
            'contact 2 phone',
            'contact_2_phone',
        ],
        'contact_3_name' => [
            'contact 3 name',
            'contact_3_name',
        ],
        'contact_3_email' => [
            'contact 3 email',
            'contact_3_email',
        ],
        'contact_3_phone' => [
            'contact 3 phone',
            'contact_3_phone',
        ],
    ];

    /**
     * Properties that are always required in every import.
     *
     * @var string[]
     */
    private const REQUIRED = [
        'email',
    ];

    /**
     * At least one of these properties must be present for group identification.
     *
     * @var string[]
     */
    private const REQUIRED_ONE_OF = [
        'group_id',
        'group_name',
    ];

    /**
     * Map column header indices to canonical property names.
     *
     * @param string[] $headers Raw header row from the spreadsheet
     * @return array<int, string> column-index => canonical property name
     */
    public function mapHeaders(array $headers): array
    {
        $mapping = [];

        foreach ($headers as $index => $rawHeader) {
            $normalised = $this->normalise((string) $rawHeader);

            foreach (self::ALIASES as $property => $aliases) {
                if (in_array($normalised, $aliases, true)) {
                    $mapping[$index] = $property;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Validate that all required columns are present in the mapping.
     *
     * @param array<int, string> $mapping The mapping from mapHeaders()
     * @return string[] List of missing property names (empty if all present)
     */
    public function validateMapping(array $mapping): array
    {
        $mapped = array_values($mapping);
        $missing = [];

        foreach (self::REQUIRED as $property) {
            if (!in_array($property, $mapped, true)) {
                $missing[] = $property;
            }
        }

        // At least one of the identification columns must be present
        $hasOneOf = false;
        foreach (self::REQUIRED_ONE_OF as $property) {
            if (in_array($property, $mapped, true)) {
                $hasOneOf = true;
                break;
            }
        }

        if (!$hasOneOf) {
            // Report all of them as missing so the error message lists the options
            foreach (self::REQUIRED_ONE_OF as $property) {
                $missing[] = $property;
            }
        }

        return $missing;
    }

    /**
     * Get human-readable labels for property names (for error messages and help text).
     *
     * @return array<string, string> property => label
     */
    public static function getPropertyLabels(): array
    {
        return [
            'group_id'            => 'Group ID',
            'group_name'          => 'Group Name',
            'email'         => 'Group Email',
            'contact_1_name'      => 'Contact 1 Name',
            'contact_1_email'     => 'Contact 1 Email',
            'contact_1_phone'     => 'Contact 1 Telephone',
            'contact_2_name'      => 'Contact 2 Name',
            'contact_2_email'     => 'Contact 2 Email',
            'contact_2_phone'     => 'Contact 2 Telephone',
            'contact_3_name'      => 'Contact 3 Name',
            'contact_3_email'     => 'Contact 3 Email',
            'contact_3_phone'     => 'Contact 3 Telephone',
        ];
    }

    /**
     * Get all accepted header aliases (for displaying help text).
     *
     * @return array<string, string[]>
     */
    public static function getAcceptedHeaders(): array
    {
        return self::ALIASES;
    }

    /**
     * Normalise a header string for matching.
     */
    private function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        // Strip non-alphanumeric characters except spaces and underscores
        $value = preg_replace('/[^a-z0-9 _]/', '', $value);

        return $value;
    }
}