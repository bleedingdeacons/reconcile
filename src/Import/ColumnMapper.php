<?php

declare(strict_types=1);

namespace Reconcile\Import;

/**
 * Column Mapper
 *
 * Maps spreadsheet column headers to Member property keys.
 *
 * The spreadsheet headers are normalised (lowercased, trimmed, special chars removed)
 * and then matched against a known set of aliases for each property.
 *
 * Currently supported properties:
 *  - anonymous_name
 *  - home_group  (string – resolved to post ID via GroupLookup)
 *  - personal_email
 *  - mobile_number
 *  - is_gsr
 *  - intergroup_position  (string – resolved to post ID via PositionLookup)
 *  - intergroup_position_rotation  (conditionally required when intergroup_position has a value)
 */
class ColumnMapper
{
    /**
     * Canonical property name => list of accepted header aliases (all lowercase)
     *
     * @var array<string, string[]>
     */
    private const ALIASES = [
        'anonymous_name' => [
            'anonymous name',
            'anonymous_name',
            'anonymousname',
        ],
        'home_group' => [
            'home group',
            'home_group',
            'homegroup',
        ],
        'personal_email' => [
            'personal email',
            'personal_email',
            'personalemail',
        ],
        'mobile_number' => [
            'mobile number',
            'mobile_number',
            'mobilenumber',
            'mobile',
        ],
        'is_gsr' => [
            'gsr',
            'is gsr',
            'is_gsr',
            'isgsr',
        ],
        'intergroup_position' => [
            'intergroup position',
            'intergroup_position',
            'intergroupposition',
            'position',
        ],
        'intergroup_position_rotation' => [
            'intergroup position rotation',
            'intergroup_position_rotation',
        ],
    ];

    /**
     * Properties that are always required in every import.
     *
     * Note: intergroup_position_rotation is conditionally required (when
     * intergroup_position has a value) — that validation is handled by
     * the MemberImporter at row level.
     *
     * @var string[]
     */
    private const REQUIRED = [
        'anonymous_name',
        'home_group',
        'personal_email',
        'mobile_number',
        'is_gsr',
        'intergroup_position',
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
     * Get human-readable labels for property names (for error messages).
     *
     * @return array<string, string> property => label
     */
    public static function getPropertyLabels(): array
    {
        return [
            'anonymous_name'                => 'Anonymous Name',
            'home_group'                    => 'Home Group',
            'personal_email'                => 'Personal Email',
            'mobile_number'                 => 'Mobile',
            'is_gsr'                        => 'GSR Status',
            'intergroup_position'           => 'Intergroup Position',
            'intergroup_position_rotation'  => 'Intergroup Position Rotation',
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
