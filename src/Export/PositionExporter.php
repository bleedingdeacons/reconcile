<?php

declare(strict_types=1);

namespace Reconcile\Export;

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

        error_log('Reconcile PositionExporter: Found ' . count($positions) . ' position(s) to export.');

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

            fputcsv($output, [
                $position->getId(),
                $position->getLongName(),
                $position->getEmail(),
                $position->getMinimumSobriety(),
                $position->getTermYears(),
                $position->getShortDescription(),
                $position->getSummary(),
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv !== false ? $csv : '';
    }
}