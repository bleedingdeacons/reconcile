<?php

declare(strict_types=1);

namespace Reconcile\Member;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Audit\Interfaces\AuditLoggerInterface;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;

/**
 * Member Exporter
 *
 * Exports member data to CSV format using the Unity MemberRepository.
 * Resolves Home Group and Intergroup Position IDs back to their names
 * so the exported file matches the import format.
 *
 * Output columns:
 *  Member ID, Anonymous Name, Home Group, Personal Email, Mobile,
 *  GSR Status, Intergroup Position, Intergroup Position Rotation
 */
class MemberExporter
{
    private ?AuditLoggerInterface $auditLogger = null;
    private ?MemberRepository $memberRepository;
    private ?GroupRepository $groupRepository;
    private ?PositionRepository $positionRepository;

    /** @var array<int, string> Cached group ID => title */
    private array $groupCache = [];

    /** @var array<int, string> Cached position ID => long name */
    private array $positionCache = [];

    public function __construct(
        ?MemberRepository $memberRepository,
        ?GroupRepository $groupRepository,
        ?PositionRepository $positionRepository,
        ?AuditLoggerInterface $auditLogger
    ) {
        $this->memberRepository = $memberRepository;
        $this->groupRepository = $groupRepository;
        $this->positionRepository = $positionRepository;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Export all members as a CSV string.
     *
     * @return string The CSV content
     * @throws \RuntimeException If the MemberRepository is not available
     */
    public function export(): string
    {

        if ($this->auditLogger === null) {
            throw new \RuntimeException('Scrutiny AuditLogger is not available. Is Scrutiny started??');
        }

        if ($this->memberRepository === null) {
            throw new \RuntimeException('Unity MemberRepository is not available. Is Unity fully configured?');
        }

        $this->buildGroupCache();
        $this->buildPositionCache();

        $members = $this->memberRepository->findAll();

        error_log('Reconcile MemberExporter: Found ' . count($members) . ' member(s) to export.');

        $output = fopen('php://temp', 'r+');

        if ($output === false) {
            throw new \RuntimeException('Could not open temporary stream for CSV export.');
        }

        // Header row — matches the import column names
        fputcsv($output, [
            'Member ID',
            'Anonymous Name',
            'Home Group',
            'Personal Email',
            'Mobile Number',
            'GSR',
            'Intergroup Position',
            'Intergroup Position Rotation',
        ]);

        // Data rows
        foreach ($members as $member) {
            if (!$member instanceof Member) {
                continue;
            }

            $homeGroupId = $member->getHomeGroup();
            $positionId = $member->getIntergroupPosition();

            fputcsv($output, [
                $member->getId(),
                $member->getAnonymousName(),
                $this->resolveGroupName($homeGroupId),
                $member->getPersonalEmail(),
                $member->getMobileNumber(),
                $member->isGSR() ? 'Yes' : 'No',
                $this->resolvePositionName($positionId),
                $member->getIntergroupPositionRotation(),
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $this->auditLogger->log(AuditLoggerInterface::ACTION_EXPORT, AuditLoggerInterface::ENTITY_MEMBER, -1, "Name, Email, Mobile", "All populated fields.");

        return $csv !== false ? $csv : '';
    }

    /**
     * Build a cache of group ID => title for name resolution.
     */
    private function buildGroupCache(): void
    {
        if ($this->groupRepository === null) {
            return;
        }

        try {
            $groups = $this->groupRepository->findAll();
            foreach ($groups as $group) {
                if ($group instanceof Group) {
                    $this->groupCache[$group->getId()] = $group->getTitle();
                }
            }
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Reconcile MemberExporter: Failed to build group cache — ' . $e->getMessage());
        }
    }

    /**
     * Build a cache of position ID => long name for name resolution.
     */
    private function buildPositionCache(): void
    {
        if ($this->positionRepository === null) {
            return;
        }

        try {
            $positions = $this->positionRepository->findAll();
            foreach ($positions as $position) {
                if ($position instanceof Position) {
                    $this->positionCache[$position->getId()] = $position->getLongName();
                }
            }
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Reconcile MemberExporter: Failed to build position cache — ' . $e->getMessage());
        }
    }

    /**
     * Resolve a group post ID to its title.
     */
    private function resolveGroupName(int $groupId): string
    {
        if ($groupId === 0) {
            return '';
        }

        return $this->groupCache[$groupId] ?? '';
    }

    /**
     * Resolve a position post ID to its long name.
     */
    private function resolvePositionName(int $positionId): string
    {
        if ($positionId === 0) {
            return '';
        }

        return $this->positionCache[$positionId] ?? '';
    }
}
