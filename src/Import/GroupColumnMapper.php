<?php

declare(strict_types=1);

namespace Reconcile\Import;

/**
 * Group Column Mapper
 *
 * Maps spreadsheet column headers to Group property keys.
 *
 * The spreadsheet headers are normalised (lowercased, trimmed, special chars removed)
 * and then matched against a known set of aliases for each property.
 *
 * Currently supported properties:
 *  - group_id       (required — existing group post ID for updates)
 *  - group_name     (optional — updates the group title if provided)
 *  - group_email    (the group's dedicated email address)
 *  - group_email_active (whether the group email is active)
 *  - contact_1_name, contact_1_email, contact_1_phone
 *  - contact_2_name, contact_2_email, contact_2_phone
 *  - contact_3_name, contact_3_email, contact_3_phone
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
        'group_email' => [
            'group email',
            'group_email',
            'groupemail',
        ],
        'group_email_active' => [
            'group email active',
            'group_email_active',
            'groupemailactive',
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
        'group_id',
        'group_email',
        'group_email_active',
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
            'group_email'         => 'Group Email',
            'group_email_active'  => 'Group Email Active',
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
