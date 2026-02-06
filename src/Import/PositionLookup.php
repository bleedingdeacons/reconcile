<?php

declare(strict_types=1);

namespace Reconcile\Import;

use Unity\Positions\Interfaces\PositionRepositoryInterface;
use Unity\Positions\Interfaces\PositionInterface;

/**
 * Position Lookup
 *
 * Resolves position name strings from spreadsheet data to their corresponding
 * WordPress post IDs by querying the PositionRepository.
 *
 * Matches against the Position's longName (case-insensitive).
 * Results are cached for the lifetime of the import.
 */
class PositionLookup
{
    private ?PositionRepositoryInterface $positionRepository;

    /**
     * Cached map of lowercase position long name => post ID
     *
     * @var array<string, int>
     */
    private array $cache = [];

    private bool $cacheBuilt = false;

    /**
     * Position names that could not be resolved during the import
     *
     * @var array<string, true>
     */
    private array $unresolvedNames = [];

    public function __construct(?PositionRepositoryInterface $positionRepository)
    {
        $this->positionRepository = $positionRepository;
    }

    /**
     * Resolve a position name string to its post ID
     *
     * @param string $positionName The position name as it appears in the spreadsheet
     * @return int The post ID, or 0 if not found
     */
    public function resolve(string $positionName): int
    {
        $positionName = trim($positionName);

        if ($positionName === '') {
            return 0;
        }

        $this->buildCache();

        $key = mb_strtolower($positionName);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        // Record unresolved for reporting
        $this->unresolvedNames[$positionName] = true;

        return 0;
    }

    /**
     * Get all position names that could not be resolved during the import
     *
     * @return string[]
     */
    public function getUnresolvedNames(): array
    {
        return array_keys($this->unresolvedNames);
    }

    /**
     * Reset unresolved names (e.g. between import batches)
     */
    public function resetUnresolved(): void
    {
        $this->unresolvedNames = [];
    }

    /**
     * Invalidate the cache so the next resolve() call rebuilds it
     */
    public function invalidateCache(): void
    {
        $this->cache = [];
        $this->cacheBuilt = false;
    }

    /**
     * Build the lookup cache from all published positions
     */
    private function buildCache(): void
    {
        if ($this->cacheBuilt) {
            return;
        }

        $this->cacheBuilt = true;

        if ($this->positionRepository === null) {
            error_log('Reconcile PositionLookup: PositionRepository is not available.');
            return;
        }

        try {
            $positions = $this->positionRepository->findAll();
        } catch (\Exception $e) {
            error_log('Reconcile PositionLookup: Failed to load positions - ' . $e->getMessage());
            return;
        }

        foreach ($positions as $position) {
            if (!$position instanceof PositionInterface) {
                continue;
            }

            $longName = mb_strtolower(trim($position->getLongName()));

            if ($longName !== '') {
                $this->cache[$longName] = $position->getId();
            }
        }
    }
}
