<?php

declare(strict_types=1);

namespace Reconcile;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Reconcile\Group\GroupExporter;
use Reconcile\Group\GroupExportHandler;
use Reconcile\Group\GroupImporter;
use Reconcile\Group\GroupImportHandler;
use Reconcile\Group\GroupLookup;
use Reconcile\Member\MemberExporter;
use Reconcile\Member\MemberExportHandler;
use Reconcile\Member\MemberImporter;
use Reconcile\Member\MemberImportHandler;
use Reconcile\Position\PositionExporter;
use Reconcile\Position\PositionExportHandler;
use Reconcile\Position\PositionImporter;
use Reconcile\Position\PositionImportHandler;
use Reconcile\Position\PositionLookup;
use Psr\Container\ContainerInterface;
use Reconcile\Admin\GroupsAdmin;
use Reconcile\Admin\MembersAdmin;
use Reconcile\Admin\PositionsAdmin;
use Scrutiny\Audit\AuditLogger;
use Scrutiny\Audit\Interfaces\AuditLoggerInterface;
use Scrutiny\Audit\Interfaces\AuditRepositoryInterface;
use Unity\Contacts\Interfaces\ContactFactory;
use Unity\Groups\Interfaces\GroupFactory;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Members\Interfaces\MemberFactory;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Positions\Interfaces\PositionFactory;
use Unity\Positions\Interfaces\PositionRepository;

/**
 * Main Plugin Class
 *
 * Initialises admin pages and import services using Unity's container.
 *
 * Menu registration happens early (via registerMenus) to guarantee the
 * admin_menu hook has not yet fired. AJAX handlers are wired later
 * (via init) once Unity's container is available.
 */
class Plugin
{
    use \Reconcile\Logger\HasLogger;

    protected static function logChannel(): string
    {
        return 'reconcile';
    }

    private static ?ContainerInterface $container = null;
    private static ?AuditLoggerInterface $auditLogger = null;
    private static ?MembersAdmin $memberAdminPage = null;
    private static ?GroupsAdmin $groupAdminPage = null;
    private static ?PositionsAdmin $positionAdminPage = null;
    private static ?MemberImportHandler $importHandler = null;
    private static ?GroupImportHandler $groupImportHandler = null;
    private static ?GroupExportHandler $groupExportHandler = null;
    private static ?MemberExportHandler $memberExportHandler = null;
    private static ?PositionImportHandler $positionImportHandler = null;
    private static ?PositionExportHandler $positionExportHandler = null;

    /**
     * Register the top-level Reconcile menu and submenu pages.
     *
     * Called early — before unity/loaded — so the menu is guaranteed
     * to appear regardless of when Unity finishes initialising.
     */
    public static function registerMenus(): void
    {
        if (!is_admin()) {
            return;
        }

        self::$memberAdminPage = new MembersAdmin();
        self::$memberAdminPage->register();

        self::$groupAdminPage = new GroupsAdmin();
        self::$groupAdminPage->register();

        self::$positionAdminPage = new PositionsAdmin();
        self::$positionAdminPage->register();

        add_action('admin_menu', [self::class, 'addMenuPages']);
    }

    /**
     * WordPress admin_menu callback — creates the top-level menu and submenus.
     */
    public static function addMenuPages(): void
    {
        // Top-level menu
        add_menu_page(
            __('Reconcile', 'reconcile'),
            __('Reconcile', 'reconcile'),
            'manage_options',
            'reconcile',
            [self::$memberAdminPage, 'renderPage'],
            'dashicons-update',
            30
        );

        // First submenu replaces the auto-generated top-level duplicate
        add_submenu_page(
            'reconcile',
            __('Reconcile — Members', 'reconcile'),
            __('Members', 'reconcile'),
            'manage_options',
            'reconcile',
            [self::$memberAdminPage, 'renderPage']
        );

        // Group submenu
        add_submenu_page(
            'reconcile',
            __('Reconcile — Groups', 'reconcile'),
            __('Groups', 'reconcile'),
            'manage_options',
            'reconcile-groups',
            [self::$groupAdminPage, 'renderPage']
        );

        // Position submenu
        add_submenu_page(
            'reconcile',
            __('Reconcile — Positions', 'reconcile'),
            __('Positions', 'reconcile'),
            'manage_options',
            'reconcile-positions',
            [self::$positionAdminPage, 'renderPage']
        );
    }

    /**
     * Check if Unity plugin is active and has required interfaces
     */
    public static function unityIsAvailable(): bool
    {
        return interface_exists('Unity\\Core\\Interfaces\\Container')
            && class_exists('Unity\\Core\\UnityServiceProvider');
    }

    /**
     * Check if Unity's member interfaces are available
     */
    public static function unityMembersAvailable(): bool
    {
        return interface_exists('Unity\\Members\\Interfaces\\MemberFactory')
            && interface_exists('Unity\\Members\\Interfaces\\Member')
            && interface_exists('Unity\\Members\\Interfaces\\MemberRepository');
    }

    /**
     * Check if Unity's group interfaces are available
     */
    public static function unityGroupsAvailable(): bool
    {
        return interface_exists('Unity\\Groups\\Interfaces\\GroupFactory')
            && interface_exists('Unity\\Groups\\Interfaces\\Group')
            && interface_exists('Unity\\Groups\\Interfaces\\GroupRepository');
    }

    /**
     * Check if Unity's position interfaces are available
     */
    public static function unityPositionsAvailable(): bool
    {
        return interface_exists('Unity\\Positions\\Interfaces\\PositionFactory')
            && interface_exists('Unity\\Positions\\Interfaces\\Position')
            && interface_exists('Unity\\Positions\\Interfaces\\PositionRepository');
    }

    /**
     * Check if Unity's contact interfaces are available
     */
    public static function unityContactsAvailable(): bool
    {
        return interface_exists('Unity\\Contacts\\Interfaces\\ContactFactory')
            && interface_exists('Unity\\Contacts\\Interfaces\\Contact');
    }

    /**
     * Check if Unity's contact interfaces are available
     */
    public static function auditLoggerAvailable(): bool
    {
        return interface_exists('Scrutiny\\Audit\\Interfaces\\AuditLoggerInterface');
    }

    /**
     * Initialise import services with Unity's container.
     *
     * Called from the unity/loaded hook once the container is ready.
     */
    public static function init(ContainerInterface $container): void
    {
        self::$container = $container;

        if (!is_admin()) {
            return;
        }

        $auditLogger = self::getAuditLogger();
        $memberRepository = self::getMemberRepository();
        $memberFactory = self::getMemberFactory();
        $groupLookup = new GroupLookup(self::getGroupRepository());
        $positionLookup = new PositionLookup(self::getPositionRepository());
        $memberImporter = new MemberImporter($memberRepository, $memberFactory, $groupLookup, $positionLookup, $auditLogger);

        self::$importHandler = new MemberImportHandler($memberImporter);
        self::$importHandler->register();

        $groupRepository = self::getGroupRepository();
        $groupFactory = self::getGroupFactory();
        $contactFactory = self::getContactFactory();
        $groupImporter = new GroupImporter($groupRepository, $groupFactory, $contactFactory, $auditLogger);

        self::$groupImportHandler = new GroupImportHandler($groupImporter);
        self::$groupImportHandler->register();

        // --- Group Export handler ---
        $groupExporter = new GroupExporter($groupRepository, $auditLogger);

        self::$groupExportHandler = new GroupExportHandler($groupExporter);
        self::$groupExportHandler->register();

        // --- Member Export handler ---
        $memberExporter = new MemberExporter(
            $memberRepository,
            self::getGroupRepository(),
            self::getPositionRepository(),
            $auditLogger
        );

        self::$memberExportHandler = new MemberExportHandler($memberExporter);
        self::$memberExportHandler->register();

        // --- Position Import AJAX handler ---
        $positionRepository = self::getPositionRepository();
        $positionFactory = self::getPositionFactory();
        $positionImporter = new PositionImporter($positionRepository, $positionFactory, $auditLogger);

        self::$positionImportHandler = new PositionImportHandler($positionImporter);
        self::$positionImportHandler->register();

        // --- Position Export handler ---
        $positionExporter = new PositionExporter($positionRepository, $auditLogger);

        self::$positionExportHandler = new PositionExportHandler($positionExporter);
        self::$positionExportHandler->register();

        self::logDebug('Initialised', ['version' => defined('RECONCILE_VERSION') ? RECONCILE_VERSION : 'unknown']);

    }

    /**
     * Get the Unity dependency container
     */
    public static function getContainer(): ?ContainerInterface
    {
        return self::$container;
    }

    public static function getAuditLogger(): ?AuditLoggerInterface
    {
        if (self::$container === null || !self::auditLoggerAvailable()) {
            return null;
        }

        try {
            return self::$container->get(AuditLoggerInterface::class);
        } catch (\Exception $e) {
            \Reconcile\Plugin::logError('Reconcile: Could not resolve Audit Logger: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return null;
        }
    }

    /**
     * Get the MemberRepository from Unity's container
     */
    public static function getMemberRepository(): ?MemberRepository
    {
        if (self::$container === null || !self::unityMembersAvailable()) {
            return null;
        }

        try {
            return self::$container->get(MemberRepository::class);
        } catch (\Exception $e) {
            \Reconcile\Plugin::logError('Reconcile: Could not resolve MemberRepository: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return null;
        }
    }

    /**
     * Get the MemberFactory from Unity's container
     */
    public static function getMemberFactory(): ?MemberFactory
    {
        if (self::$container === null || !self::unityMembersAvailable()) {
            return null;
        }

        try {
            return self::$container->get(MemberFactory::class);
        } catch (\Exception $e) {
            \Reconcile\Plugin::logError('Reconcile: Could not resolve MemberFactory: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return null;
        }
    }

    /**
     * Get the GroupRepository from Unity's container
     */
    public static function getGroupRepository(): ?GroupRepository
    {
        if (self::$container === null || !self::unityGroupsAvailable()) {
            return null;
        }

        try {
            return self::$container->get(GroupRepository::class);
        } catch (\Exception $e) {
            \Reconcile\Plugin::logError('Reconcile: Could not resolve GroupRepository: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return null;
        }
    }

    /**
     * Get the GroupFactory from Unity's container
     */
    public static function getGroupFactory(): ?GroupFactory
    {
        if (self::$container === null || !self::unityGroupsAvailable()) {
            return null;
        }

        try {
            return self::$container->get(GroupFactory::class);
        } catch (\Exception $e) {
            \Reconcile\Plugin::logError('Reconcile: Could not resolve GroupFactory: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return null;
        }
    }

    /**
     * Get the ContactFactory from Unity's container
     */
    public static function getContactFactory(): ?ContactFactory
    {
        if (self::$container === null || !self::unityContactsAvailable()) {
            return null;
        }

        try {
            return self::$container->get(ContactFactory::class);
        } catch (\Exception $e) {
            \Reconcile\Plugin::logError('Reconcile: Could not resolve ContactFactory: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return null;
        }
    }

    /**
     * Get the PositionRepository from Unity's container
     */
    public static function getPositionRepository(): ?PositionRepository
    {
        if (self::$container === null || !self::unityPositionsAvailable()) {
            return null;
        }

        try {
            return self::$container->get(PositionRepository::class);
        } catch (\Exception $e) {
            \Reconcile\Plugin::logError('Reconcile: Could not resolve PositionRepository: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return null;
        }
    }

    /**
     * Get the PositionFactory from Unity's container
     */
    public static function getPositionFactory(): ?PositionFactory
    {
        if (self::$container === null || !self::unityPositionsAvailable()) {
            return null;
        }

        try {
            return self::$container->get(PositionFactory::class);
        } catch (\Exception $e) {
            \Reconcile\Plugin::logError('Reconcile: Could not resolve PositionFactory: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return null;
        }
    }
}