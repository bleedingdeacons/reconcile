<?php

declare(strict_types=1);

namespace Reconcile;

use Reconcile\Admin\MemberImportAdmin;
use Reconcile\Admin\ImportHandler;
use Reconcile\Import\GroupLookup;
use Reconcile\Import\MemberImporter;
use Reconcile\Import\PositionLookup;
use Unity\Core\DependencyContainer;
use Unity\Members\Interfaces\MemberRepositoryInterface;
use Unity\Groups\Interfaces\GroupRepositoryInterface;
use Unity\Positions\Interfaces\PositionRepositoryInterface;

/**
 * Main Plugin Class
 *
 * Initialises admin pages and import services using Unity's container.
 */
class Plugin
{
    private static ?DependencyContainer $container = null;
    private static ?MemberImportAdmin $adminPage = null;
    private static ?ImportHandler $importHandler = null;

    /**
     * Check if Unity plugin is active and has required interfaces
     */
    public static function unityIsAvailable(): bool
    {
        return class_exists('Unity\\Core\\DependencyContainer')
            && class_exists('Unity\\Core\\UnityServiceProvider');
    }

    /**
     * Check if Unity's member interfaces are available
     */
    public static function unityMembersAvailable(): bool
    {
        return interface_exists('Unity\\Members\\Interfaces\\MemberFactoryInterface')
            && interface_exists('Unity\\Members\\Interfaces\\MemberInterface')
            && interface_exists('Unity\\Members\\Interfaces\\MemberRepositoryInterface');
    }

    /**
     * Check if Unity's group interfaces are available
     */
    public static function unityGroupsAvailable(): bool
    {
        return interface_exists('Unity\\Groups\\Interfaces\\GroupFactoryInterface')
            && interface_exists('Unity\\Groups\\Interfaces\\GroupInterface')
            && interface_exists('Unity\\Groups\\Interfaces\\GroupRepositoryInterface');
    }

    /**
     * Check if Unity's position interfaces are available
     */
    public static function unityPositionsAvailable(): bool
    {
        return interface_exists('Unity\\Positions\\Interfaces\\PositionFactoryInterface')
            && interface_exists('Unity\\Positions\\Interfaces\\PositionInterface')
            && interface_exists('Unity\\Positions\\Interfaces\\PositionRepositoryInterface');
    }

    /**
     * Initialise the plugin with Unity's container
     */
    public static function init(DependencyContainer $container): void
    {
        self::$container = $container;

        if (!is_admin()) {
            return;
        }

        // Build the import handler with Unity dependencies
        $memberRepository = self::getMemberRepository();
        $groupLookup = new GroupLookup(self::getGroupRepository());
        $positionLookup = new PositionLookup(self::getPositionRepository());
        $memberImporter = new MemberImporter($memberRepository, $groupLookup, $positionLookup);

        self::$importHandler = new ImportHandler($memberImporter);
        self::$importHandler->register();

        self::$adminPage = new MemberImportAdmin();
        self::$adminPage->register();
    }

    /**
     * Get the Unity dependency container
     */
    public static function getContainer(): ?DependencyContainer
    {
        return self::$container;
    }

    /**
     * Get the MemberRepository from Unity's container
     */
    public static function getMemberRepository(): ?MemberRepositoryInterface
    {
        if (self::$container === null || !self::unityMembersAvailable()) {
            return null;
        }

        try {
            return self::$container->get(MemberRepositoryInterface::class);
        } catch (\Exception $e) {
            error_log('Reconcile: Could not resolve MemberRepository - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the GroupRepository from Unity's container
     */
    public static function getGroupRepository(): ?GroupRepositoryInterface
    {
        if (self::$container === null || !self::unityGroupsAvailable()) {
            return null;
        }

        try {
            return self::$container->get(GroupRepositoryInterface::class);
        } catch (\Exception $e) {
            error_log('Reconcile: Could not resolve GroupRepository - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the PositionRepository from Unity's container
     */
    public static function getPositionRepository(): ?PositionRepositoryInterface
    {
        if (self::$container === null || !self::unityPositionsAvailable()) {
            return null;
        }

        try {
            return self::$container->get(PositionRepositoryInterface::class);
        } catch (\Exception $e) {
            error_log('Reconcile: Could not resolve PositionRepository - ' . $e->getMessage());
            return null;
        }
    }
}
