<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Reconcile\Group\GroupExporter;
use Reconcile\Group\GroupExportHandler;
use Reconcile\Group\GroupImporter;
use Reconcile\Group\GroupImportHandler;
use Reconcile\Group\GroupLookup;
use Reconcile\Member\MemberExporter;
use Reconcile\Member\MemberExportHandler;
use Reconcile\Member\MemberImporter;
use Reconcile\Member\MemberImportHandler;
use Reconcile\Plugin;
use Reconcile\Position\PositionExporter;
use Reconcile\Position\PositionExportHandler;
use Reconcile\Position\PositionImporter;
use Reconcile\Position\PositionImportHandler;
use Reconcile\Position\PositionLookup;
use ReflectionClass;
use RuntimeException;
use Unity\Contacts\Interfaces\ContactFactory;
use Unity\Core\Interfaces\Configuration;
use Unity\Groups\Interfaces\GroupFactory;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Members\Interfaces\MemberFactory;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Positions\Interfaces\PositionFactory;
use Unity\Positions\Interfaces\PositionRepository;

/**
 * Tests for the Plugin bootstrap: the Unity-container accessors (the bulk of
 * the class), the service/handler registration wiring, the availability
 * probes, and the admin menu registration. No production code is exercised
 * through a real WordPress or Unity runtime — a fake PSR-11 container stands
 * in, and the bootstrap's hand-rolled WP stubs record the hooks/menus wired.
 *
 * @covers \Reconcile\Plugin
 */
class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStatics();
        $GLOBALS['__reconcile_test_is_admin'] = true;
        $GLOBALS['__reconcile_test_actions'] = [];
        $GLOBALS['__reconcile_test_menu_pages'] = [];
        $GLOBALS['__reconcile_test_submenu_pages'] = [];
    }

    protected function tearDown(): void
    {
        $this->resetStatics();
        unset($GLOBALS['__reconcile_test_is_admin']);
        parent::tearDown();
    }

    // --- container accessor ----------------------------------------------

    /** @test */
    public function get_container_is_null_before_init_and_set_after(): void
    {
        $this->assertNull(Plugin::getContainer());

        $container = $this->fullContainer();
        Plugin::init($container);

        $this->assertSame($container, Plugin::getContainer());
    }

    // --- typed accessors: null / resolve / exception paths ---------------

    /**
     * @test
     * @dataProvider accessorProvider
     */
    public function accessor_returns_null_when_no_container(string $method): void
    {
        $this->setContainer(null);
        $this->assertNull(Plugin::$method());
    }

    /**
     * @test
     * @dataProvider accessorProvider
     */
    public function accessor_resolves_from_the_container(string $method, string $interface): void
    {
        $service = $this->createMock($interface);
        $this->setContainer(new ReconcileFakeContainer([$interface => $service]));

        $this->assertSame($service, Plugin::$method());
    }

    /**
     * @test
     * @dataProvider accessorProvider
     */
    public function accessor_returns_null_and_logs_when_resolution_throws(string $method): void
    {
        $this->setContainer(new ThrowingContainer());
        $this->assertNull(Plugin::$method());
    }

    /**
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function accessorProvider(): array
    {
        return [
            'member repository'  => ['getMemberRepository', MemberRepository::class],
            'member factory'     => ['getMemberFactory', MemberFactory::class],
            'group repository'   => ['getGroupRepository', GroupRepository::class],
            'group factory'      => ['getGroupFactory', GroupFactory::class],
            'contact factory'    => ['getContactFactory', ContactFactory::class],
            'position repository' => ['getPositionRepository', PositionRepository::class],
            'position factory'   => ['getPositionFactory', PositionFactory::class],
        ];
    }

    // --- availability probes ---------------------------------------------

    /** @test */
    public function availability_probes_are_true_when_unity_is_on_the_classpath(): void
    {
        // The bootstrap loads Unity's real interfaces as a sibling, so every
        // probe resolves true — which exercises the interface_exists chains.
        $this->assertTrue(Plugin::unityIsAvailable());
        $this->assertTrue(Plugin::unityMembersAvailable());
        $this->assertTrue(Plugin::unityGroupsAvailable());
        $this->assertTrue(Plugin::unityPositionsAvailable());
        $this->assertTrue(Plugin::unityContactsAvailable());
    }

    // --- registerServices / registerHandlers -----------------------------

    /** @test */
    public function register_services_binds_and_resolves_every_reconcile_service(): void
    {
        $container = $this->fullContainer();

        (new \ReflectionMethod(Plugin::class, 'registerServices'))->invoke(null, $container);

        foreach ([
            GroupLookup::class,
            PositionLookup::class,
            GroupImporter::class,
            GroupExporter::class,
            MemberImporter::class,
            MemberExporter::class,
            PositionImporter::class,
            PositionExporter::class,
            GroupImportHandler::class,
            GroupExportHandler::class,
            MemberImportHandler::class,
            MemberExportHandler::class,
            PositionImportHandler::class,
            PositionExportHandler::class,
        ] as $id) {
            $this->assertInstanceOf($id, $container->get($id), "$id should resolve");
        }
    }

    /** @test */
    public function init_registers_handler_hooks_when_in_admin(): void
    {
        Plugin::init($this->fullContainer());

        $hooks = array_column($GLOBALS['__reconcile_test_actions'], 'hook');
        foreach ([
            'wp_ajax_reconcile_import',
        ] as $ajaxHook) {
            $this->assertContains($ajaxHook, $hooks, "handler must wire $ajaxHook");
        }
        // Six handlers each register one AJAX action.
        $this->assertGreaterThanOrEqual(6, count($GLOBALS['__reconcile_test_actions']));
    }

    /** @test */
    public function init_bails_out_when_not_in_admin(): void
    {
        $GLOBALS['__reconcile_test_is_admin'] = false;
        $container = $this->fullContainer();

        Plugin::init($container);

        // Container is stored, but no services/handlers wired.
        $this->assertSame($container, Plugin::getContainer());
        $this->assertSame([], $GLOBALS['__reconcile_test_actions']);
    }

    // --- menu registration ------------------------------------------------

    /** @test */
    public function register_menus_bails_out_when_not_in_admin(): void
    {
        $GLOBALS['__reconcile_test_is_admin'] = false;

        Plugin::registerMenus();

        $this->assertSame([], $GLOBALS['__reconcile_test_actions']);
    }

    /** @test */
    public function register_menus_wires_the_admin_pages_and_menu_hook(): void
    {
        Plugin::registerMenus();

        $hooks = array_column($GLOBALS['__reconcile_test_actions'], 'hook');
        $this->assertContains('admin_menu', $hooks);

        // addMenuPages() then builds the top-level menu plus three submenus.
        Plugin::addMenuPages();
        $this->assertContains('reconcile', $GLOBALS['__reconcile_test_menu_pages']);
        $submenuSlugs = array_column($GLOBALS['__reconcile_test_submenu_pages'], 'slug');
        $this->assertContains('reconcile', $submenuSlugs);
        $this->assertContains('reconcile-groups', $submenuSlugs);
        $this->assertContains('reconcile-positions', $submenuSlugs);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * A container preseeded with mocks for every Unity leaf dependency the
     * Reconcile service closures resolve.
     */
    private function fullContainer(): ReconcileFakeContainer
    {
        return new ReconcileFakeContainer([
            Configuration::class      => $this->createMock(Configuration::class),
            MemberRepository::class   => $this->createMock(MemberRepository::class),
            MemberFactory::class      => $this->createMock(MemberFactory::class),
            GroupRepository::class    => $this->createMock(GroupRepository::class),
            GroupFactory::class       => $this->createMock(GroupFactory::class),
            ContactFactory::class     => $this->createMock(ContactFactory::class),
            PositionRepository::class => $this->createMock(PositionRepository::class),
            PositionFactory::class    => $this->createMock(PositionFactory::class),
        ]);
    }

    private function setContainer(?ContainerInterface $container): void
    {
        $prop = (new ReflectionClass(Plugin::class))->getProperty('container');
        $prop->setValue(null, $container);
    }

    private function resetStatics(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        foreach ([
            'container', 'memberAdminPage', 'groupAdminPage', 'positionAdminPage',
            'importHandler', 'groupImportHandler', 'groupExportHandler',
            'memberExportHandler', 'positionImportHandler', 'positionExportHandler',
        ] as $prop) {
            if ($ref->hasProperty($prop)) {
                $ref->getProperty($prop)->setValue(null, null);
            }
        }
    }
}

/**
 * Minimal PSR-11 container with Unity's register() extension: presets are
 * pre-built leaf services; everything else runs its registered factory once.
 */
final class ReconcileFakeContainer implements ContainerInterface
{
    /** @var array<string, callable> */
    private array $factories = [];
    /** @var array<string, mixed> */
    private array $instances;

    /** @param array<string, mixed> $presets */
    public function __construct(array $presets = [])
    {
        $this->instances = $presets;
    }

    public function register(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }
        throw new RuntimeException('No service registered for ' . $id);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }
}

/** A container whose every get() throws, to drive the accessor catch paths. */
final class ThrowingContainer implements ContainerInterface
{
    public function get(string $id): mixed
    {
        throw new RuntimeException('boom resolving ' . $id);
    }

    public function has(string $id): bool
    {
        return true;
    }
}
