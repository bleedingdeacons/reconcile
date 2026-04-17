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
 * Group Exporter
 *
 * Exports group data to CSV format using the Unity GroupRepository.
 *
 * Output columns match the group import format:
 *  Group ID, Group Name, Group Email,
 *  Contact 1 Name, Contact 1 Email, Contact 1 Phone,
 *  Contact 2 Name, Contact 2 Email, Contact 2 Phone,
 *  Contact 3 Name, Contact 3 Email, Contact 3 Phone
 */
class GroupExporter
{
    private ?GroupRepository $groupRepository;

    public function __construct(?GroupRepository $groupRepository)
    {
        $this->groupRepository = $groupRepository;
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
     * Export all groups as a CSV string.
     *
     * @return string The CSV content
     * @throws \RuntimeException If the GroupRepository is not available
     */
    public function export(): string
    {
        if ($this->groupRepository === null) {
            throw new \RuntimeException('Unity GroupRepository is not available. Is Unity fully configured?');
        }

        $groups = $this->groupRepository->findAll();

        \Reconcile\Plugin::logInfo('Reconcile GroupExporter: Found ' . count($groups) . ' group(s) to export.');

        $output = fopen('php://temp', 'r+');

        if ($output === false) {
            throw new \RuntimeException('Could not open temporary stream for CSV export.');
        }

        // Header row
        fputcsv($output, [
            'Group ID',
            'Group Name',
            'Group Email',
            'Contact 1 Name',
            'Contact 1 Email',
            'Contact 1 Phone',
            'Contact 2 Name',
            'Contact 2 Email',
            'Contact 2 Phone',
            'Contact 3 Name',
            'Contact 3 Email',
            'Contact 3 Phone',
        ]);

        // Data rows
        foreach ($groups as $group) {
            if (!$group instanceof Group) {
                continue;
            }

            $contacts = $group->getContacts();
            $row = [
                $group->getId(),
                $group->getTitle(),
                $group->getEmail(),
            ];

            // Pad to exactly 3 contact slots
            for ($i = 0; $i < 3; $i++) {
                if (isset($contacts[$i])) {
                    $row[] = $contacts[$i]->getName();
                    $row[] = $contacts[$i]->getEmail();
                    $row[] = $contacts[$i]->getPhone();
                } else {
                    $row[] = '';
                    $row[] = '';
                    $row[] = '';
                }
            }

            $row = array_map([self::class, 'sanitizeCsvField'], $row);

            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        /** @see \Scrutiny\Audit\AuditTracker::onGroupExport() */
        do_action('unity/group_export', count($groups), 'Group Contacts');

        return $csv !== false ? $csv : '';
    }
}