<?php

declare(strict_types=1);

namespace Reconcile;

use Reconcile\Admin\MemberImportAdmin;
use Reconcile\Admin\ImportHandler;
use Reconcile\Import\GroupLookup;
use Reconcile\Import\MemberImporter;
use Reconcile\Import\PositionLookup;
use Psr\Container\ContainerInterface;
use Unity\Members\Interfaces\MemberFactory;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Positions\Interfaces\PositionRepository;

/**
 * Main Plugin Class
 *
 * Initialises admin pages and import services using Unity's container.
 */
class Plugin
{
    private static ?ContainerInterface $container = null;
    private static ?MemberImportAdmin $adminPage = null;
    private static ?ImportHandler $importHandler = null;

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
     * Initialise the plugin with Unity's container
     */
    public static function init(ContainerInterface $container): void
    {
        self::$container = $container;

        if (!is_admin()) {
            return;
        }

        // Build the import handler with Unity dependencies
        $memberRepository = self::getMemberRepository();
        $memberFactory = self::getMemberFactory();
        $groupLookup = new GroupLookup(self::getGroupRepository());
        $positionLookup = new PositionLookup(self::getPositionRepository());
        $memberImporter = new MemberImporter($memberRepository, $memberFactory, $groupLookup, $positionLookup);

        self::$importHandler = new ImportHandler($memberImporter);
        self::$importHandler->register();

        self::$adminPage = new MemberImportAdmin();
        self::$adminPage->register();
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