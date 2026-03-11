<?php

declare(strict_types=1);

namespace Reconcile;

use Reconcile\Admin\MemberImportAdmin;
use Reconcile\Admin\GroupImportAdmin;
use Reconcile\Admin\ImportHandler;
use Reconcile\Admin\GroupImportHandler;
use Reconcile\Import\GroupLookup;
use Reconcile\Import\MemberImporter;
use Reconcile\Import\GroupImporter;
use Reconcile\Import\PositionLookup;
use Psr\Container\ContainerInterface;
use Unity\Contacts\Interfaces\ContactFactory;
use Unity\Members\Interfaces\MemberFactory;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Groups\Interfaces\GroupFactory;
use Unity\Groups\Interfaces\GroupRepository;
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
    private static ?ContainerInterface $container = null;
    private static ?MemberImportAdmin $memberAdminPage = null;
    private static ?GroupImportAdmin $groupAdminPage = null;
    private static ?ImportHandler $importHandler = null;
    private static ?GroupImportHandler $groupImportHandler = null;

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

        self::$memberAdminPage = new MemberImportAdmin();
        self::$memberAdminPage->register();

        self::$groupAdminPage = new GroupImportAdmin();
        self::$groupAdminPage->register();

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
            __('Reconcile — Member Import', 'reconcile'),
            __('Member Import', 'reconcile'),
            'manage_options',
            'reconcile',
            [self::$memberAdminPage, 'renderPage']
        );

        // Group Import submenu
        add_submenu_page(
            'reconcile',
            __('Reconcile — Group Import', 'reconcile'),
            __('Group Import', 'reconcile'),
            'manage_options',
            'reconcile-groups',
            [self::$groupAdminPage, 'renderPage']
        );
    }

    /**
     * Check if Unity plugin is active and has required interfaces
     */
    public static function unityIsAvailable(): bool
    {
        return class_exists('Unity\\Core\\Interfaces\\Container')
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

        // --- Member Import AJAX handler ---
        $memberRepository = self::getMemberRepository();
        $memberFactory = self::getMemberFactory();
        $groupLookup = new GroupLookup(self::getGroupRepository());
        $positionLookup = new PositionLookup(self::getPositionRepository());
        $memberImporter = new MemberImporter($memberRepository, $memberFactory, $groupLookup, $positionLookup);

        self::$importHandler = new ImportHandler($memberImporter);
        self::$importHandler->register();

        // --- Group Import AJAX handler ---
        $groupRepository = self::getGroupRepository();
        $groupFactory = self::getGroupFactory();
        $contactFactory = self::getContactFactory();
        $groupImporter = new GroupImporter($groupRepository, $groupFactory, $contactFactory);

        self::$groupImportHandler = new GroupImportHandler($groupImporter);
        self::$groupImportHandler->register();
    }

    /**
     * Get the Unity dependency container
     */
    public static function getContainer(): ?ContainerInterface
    {
        return self::$container;
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
            error_log('Reconcile: Could not resolve MemberRepository - ' . $e->getMessage());
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
            error_log('Reconcile: Could not resolve MemberFactory - ' . $e->getMessage());
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
            error_log('Reconcile: Could not resolve GroupRepository - ' . $e->getMessage());
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
            error_log('Reconcile: Could not resolve GroupFactory - ' . $e->getMessage());
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
            error_log('Reconcile: Could not resolve ContactFactory - ' . $e->getMessage());
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
            error_log('Reconcile: Could not resolve PositionRepository - ' . $e->getMessage());
            return null;
        }
    }
}
