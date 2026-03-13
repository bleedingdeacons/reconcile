<?php

declare(strict_types=1);

namespace Reconcile\Import;

/**
 * Position Column Mapper
 *
 * Maps spreadsheet column headers to Position property keys.
 *
 * The spreadsheet headers are normalised (lowercased, trimmed, special chars removed)
 * and then matched against a known set of aliases for each property.
 *
 * Currently supported properties:
 *  - position_id          (identifies position by post ID — required if position_name not provided)
 *  - position_name        (identifies position by long name, or updates name if position_id also provided)
 *  - email                (the position's email address)
 *  - minimum_sobriety     (minimum sobriety requirement in months)
 *  - term_years           (term length in years)
 *  - short_description    (short description of the position)
 *  - summary              (summary text for the position)
 *
 * Either position_id or position_name (or both) must be present as a column header.
 */
class PositionColumnMapper
{
    /**
     * Canonical property name => list of accepted header aliases (all lowercase)
     *
     * @var array<string, string[]>
     */
    private const ALIASES = [
        'position_id' => [
            'position id',
            'position_id',
            'positionid',
        ],
        'position_name' => [
            'position name',
            'position_name',
            'positionname',
        ],
        'email' => [
            'position email',
            'email',
            'positionemail',
        ],
        'minimum_sobriety' => [
            'minimum sobriety',
            'minimum_sobriety',
            'minimumsobriety',
            'min sobriety',
            'min_sobriety',
            'sobriety',
        ],
        'term_years' => [
            'term years',
            'term_years',
            'termyears',
            'term length',
            'term_length',
            'termlength',
        ],
        'short_description' => [
            'short description',
            'short_description',
            'shortdescription',
        ],
        'summary' => [
            'summary',
        ],
    ];

    /**
     * Properties that are always required in every import.
     *
     * @var string[]
     */
    private const REQUIRED = [];

    /**
     * At least one of these properties must be present for position identification.
     *
     * @var string[]
     */
    private const REQUIRED_ONE_OF = [
        'position_id',
        'position_name',
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
            'position_id'       => 'Position ID',
            'position_name'     => 'Position Name',
            'email'             => 'Position Email',
            'minimum_sobriety'  => 'Minimum Sobriety',
            'term_years'        => 'Term Years',
            'short_description' => 'Short Description',
            'summary'           => 'Summary',
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