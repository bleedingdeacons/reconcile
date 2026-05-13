<?php

declare(strict_types=1);

namespace Reconcile\Member;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

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
 *  GSR Status, Intergroup Position, Intergroup Position Rotation,
 *  12th Stepper, Area, Accepts
 *
 * The Accepts column round-trips with the importer: ACF stored values
 * (accepts-male, accepts-female, accepts-non-binary, accepts-all) are
 * translated to human labels (Male, Female, Non-Binary, All) and joined
 * with the pipe character.
 */
class MemberExporter
{
    /**
     * Reverse map of the ACF `member-accepts` checkbox: stored value => label.
     *
     * Symmetric with MemberImporter::ACCEPTS_LABEL_TO_VALUE so that an
     * export-then-import round-trip is lossless.
     *
     * @var array<string, string>
     */
    private const ACCEPTS_VALUE_TO_LABEL = [
        'accepts-male'       => 'Male',
        'accepts-female'     => 'Female',
        'accepts-non-binary' => 'Non-Binary',
        'accepts-all'        => 'All',
    ];

    private ?MemberRepository $memberRepository;
    private ?GroupRepository $groupRepository;
    private ?PositionRepository $positionRepository;

    /** @var array<int, string> Cached group ID => title */
    private array $groupCache = [];

    /** @var array<int, string> Cached position ID => long name */
    private array $positionCache = [];

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

    public function __construct(
        ?MemberRepository $memberRepository,
        ?GroupRepository $groupRepository,
        ?PositionRepository $positionRepository
    ) {
        $this->memberRepository = $memberRepository;
        $this->groupRepository = $groupRepository;
        $this->positionRepository = $positionRepository;
    }

    /**
     * Export all members as a CSV string.
     *
     * @return string The CSV content
     * @throws \RuntimeException If the MemberRepository is not available
     */
    public function export(): string
    {

        if ($this->memberRepository === null) {
            throw new \RuntimeException('Unity MemberRepository is not available. Is Unity fully configured?');
        }

        $this->buildGroupCache();
        $this->buildPositionCache();

        $members = $this->memberRepository->findAll();

        \Reconcile\Plugin::logInfo('Reconcile MemberExporter: Found ' . count($members) . ' member(s) to export.');

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
            '12th Stepper',
            'Area',
            'Accepts',
        ]);

        // Data rows
        foreach ($members as $member) {
            if (!$member instanceof Member) {
                continue;
            }

            $homeGroupId = $member->getHomeGroup();
            $positionId = $member->getIntergroupPosition();

            $row = [
                $member->getId(),
                $member->getAnonymousName(),
                $this->resolveGroupName($homeGroupId),
                $member->getPersonalEmail(),
                $member->getMobileNumber(),
                $member->isGSR() ? 'Yes' : 'No',
                $this->resolvePositionName($positionId),
                $member->getIntergroupPositionRotation(),
                $member->isTwelfthStepper() ? 'Yes' : 'No',
                $member->getArea(),
                $this->formatAccepts($member->getAccepts()),
            ];

            $row = array_map([self::class, 'sanitizeCsvField'], $row);

            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        /** @see \Scrutiny\Audit\AuditTracker::onMemberExport() */
        do_action('unity/member_export', count($members), 'Name, Email, Mobile');

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
            \Reconcile\Plugin::logError('Reconcile MemberExporter: Failed to build group cache: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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
            \Reconcile\Plugin::logError('Reconcile MemberExporter: Failed to build position cache: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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

    /**
     * Format an ACF `member-accepts` value list as a pipe-separated string
     * of human-readable labels, suitable for the CSV cell.
     *
     * Unknown stored values are passed through verbatim so the operator can
     * see and clean them up rather than having them silently dropped.
     *
     * @param array<int, string> $values
     */
    private function formatAccepts(array $values): string
    {
        $labels = [];
        foreach ($values as $value) {
            $labels[] = self::ACCEPTS_VALUE_TO_LABEL[$value] ?? $value;
        }
        return implode('|', $labels);
    }
}