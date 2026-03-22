<?php

declare(strict_types=1);

namespace Reconcile\Group;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Audit\Interfaces\AuditLoggerInterface;
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

    private ?AuditLoggerInterface $auditLogger;

    public function __construct(?GroupRepository $groupRepository, ?AuditLoggerInterface $auditLogger)
    {
        $this->groupRepository = $groupRepository;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Export all groups as a CSV string.
     *
     * @return string The CSV content
     * @throws \RuntimeException If the GroupRepository is not available
     */
    public function export(): string
    {
        if ($this->auditLogger === null) {
            throw new \RuntimeException('Scrutiny AuditLogger is not available. Is Scrutiny started??');
        }

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

            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $this->auditLogger->log(AuditLoggerInterface::ACTION_EXPORT, AuditLoggerInterface::ENTITY_GROUP, -1, "Group Contacts", "Name, Email, Telephone");

        return $csv !== false ? $csv : '';
    }
}
