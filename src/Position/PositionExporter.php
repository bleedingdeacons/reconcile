<?php

declare(strict_types=1);

namespace Reconcile\Position;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;

/**
 * Position Exporter
 *
 * Exports position data to CSV format using the Unity PositionRepository.
 *
 * Output columns match the position import format:
 *  Position ID, Position Name, Position Email,
 *  Minimum Sobriety, Term Years, Short Description, Summary
 */
class PositionExporter
{
    private ?PositionRepository $positionRepository;

    public function __construct(?PositionRepository $positionRepository)
    {
        $this->positionRepository = $positionRepository;
    }

    /**
     * Sanitize a single CSV field to prevent formula injection in spreadsheet applications.
     *
     * Any value whose first non-whitespace character is one of = + - @ \t \r
     * is prefixed with a single quote so that Excel/LibreOffice treats it as text.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function sanitizeCsvField(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $trimmed = ltrim($value);
        if ($trimmed !== '' && str_contains("=+-@\t\r", $trimmed[0])) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Export all positions as a CSV string.
     *
     * @return string The CSV content
     * @throws \RuntimeException If the PositionRepository is not available
     */
    public function export(): string
    {
        if ($this->positionRepository === null) {
            throw new \RuntimeException('Unity PositionRepository is not available. Is Unity fully configured?');
        }

        $positions = $this->positionRepository->findAll();

        \Reconcile\Plugin::logInfo('Reconcile PositionExporter: Found ' . count($positions) . ' position(s) to export.');

        $output = fopen('php://temp', 'r+');

        if ($output === false) {
            throw new \RuntimeException('Could not open temporary stream for CSV export.');
        }

        // Header row
        fputcsv($output, [
            'Position ID',
            'Position Name',
            'Position Email',
            'Minimum Sobriety',
            'Term Years',
            'Short Description',
            'Summary',
        ]);

        // Data rows
        foreach ($positions as $position) {
            if (!$position instanceof Position) {
                continue;
            }

            $row = [
                $position->getId(),
                $position->getLongName(),
                $position->getEmail(),
                $position->getMinimumSobriety(),
                $position->getTermYears(),
                $position->getShortDescription(),
                $position->getSummary(),
            ];

            $row = array_map([self::class, 'sanitizeCsvField'], $row);

            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        /** @see \Scrutiny\Audit\AuditTracker::onPositionExport() */
        do_action('unity/position_export', count($positions), 'Position Details');

        return $csv !== false ? $csv : '';
    }
}