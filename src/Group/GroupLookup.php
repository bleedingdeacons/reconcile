<?php

declare(strict_types=1);

namespace Reconcile\Group;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;

/**
 * Group Lookup
 *
 * Resolves group name strings from spreadsheet data to their corresponding
 * WordPress post IDs by querying the GroupRepository.
 *
 * Results are cached for the lifetime of the import to avoid repeated queries.
 */
class GroupLookup
{
    private ?GroupRepository $groupRepository;

    /**
     * Cached map of lowercase group title => post ID
     *
     * @var array<string, int>
     */
    private array $cache = [];

    private bool $cacheBuilt = false;

    /**
     * Group names that could not be resolved during the import
     *
     * @var array<string, true>
     */
    private array $unresolvedNames = [];

    public function __construct(?GroupRepository $groupRepository)
    {
        $this->groupRepository = $groupRepository;
    }

    /**
     * Resolve a group name string to its post ID
     *
     * @param string $groupName The group name as it appears in the spreadsheet
     * @return int The post ID, or 0 if not found
     */
    public function resolve(string $groupName): int
    {
        $groupName = trim($groupName);

        if ($groupName === '') {
            return 0;
        }

        $this->buildCache();

        $key = mb_strtolower($groupName);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        // Record unresolved for reporting
        $this->unresolvedNames[$groupName] = true;

        return 0;
    }

    /**
     * Get all group names that could not be resolved during the import
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
     * Build the lookup cache from all published groups
     */
    private function buildCache(): void
    {
        if ($this->cacheBuilt) {
            return;
        }

        $this->cacheBuilt = true;

        if ($this->groupRepository === null) {
            \Reconcile\Plugin::logError('Reconcile GroupLookup: GroupRepository is not available.');
            return;
        }

        try {
            $groups = $this->groupRepository->findAll();
        } catch (\Exception $e) {
            \Reconcile\Plugin::logError('Reconcile GroupLookup: Failed to load groups: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return;
        }

        foreach ($groups as $group) {
            if (!$group instanceof Group) {
                continue;
            }

            $title = mb_strtolower(trim($group->getTitle()));

            if ($title !== '') {
                $this->cache[$title] = $group->getId();
            }
        }
    }
}
