<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use function Brain\Monkey\Actions\has;
use BleedingDeacons\WpMocks\WpState;
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

/*
 * Tests for the Plugin bootstrap: the Unity-container accessors (the bulk of
 * the class), the service/handler registration wiring, the availability
 * probes, and the admin menu registration. No production code is exercised
 * through a real WordPress or Unity runtime — a fake PSR-11 container stands
 * in, and the bootstrap's hand-rolled WP stubs record the hooks/menus wired.
 */

covers(Plugin::class);

// --- helpers ----------------------------------------------------------
/**
 * @return array<string, array{0: string, 1: class-string}>
 */
function pluginAccessorCases(): array
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

/**
 * The same cases with the interface dropped, for the tests that take only
 * the method name. PHPUnit 13 warns when a provider hands a test more
 * arguments than it accepts, and failOnWarning turns that into a failure.
 *
 * @return array<string, array{string}>
 */
function pluginAccessorMethods(): array
{
    return array_map(
        static fn (array $case): array => [$case[0]],
        pluginAccessorCases()
    );
}

function setPluginContainer(?ContainerInterface $container): void
{
    $prop = (new ReflectionClass(Plugin::class))->getProperty('container');
    $prop->setValue(null, $container);
}

function resetPluginStatics(): void
{
    $ref = new ReflectionClass(Plugin::class);
    foreach (
        [
        'container', 'memberAdminPage', 'groupAdminPage', 'positionAdminPage',
        'importHandler', 'groupImportHandler', 'groupExportHandler',
        'memberExportHandler', 'positionImportHandler', 'positionExportHandler',
        ] as $prop
    ) {
        if ($ref->hasProperty($prop)) {
            $ref->getProperty($prop)->setValue(null, null);
        }
    }
}

beforeEach(function () {
    resetPluginStatics();
    // parent::setUp() clears WpState (menus included) and starts a fresh
    // Brain Monkey run, so the hook store is empty too. is_admin() defaults
    // to true there, which is what most of these tests want.

    // A container preseeded with mocks for every Unity leaf dependency the
    // Reconcile service closures resolve.
    $this->fullContainer = function (): ReconcileFakeContainer {
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
    };
});

afterEach(function () {
    resetPluginStatics();
});

// --- container accessor ----------------------------------------------
it('has no container before init() and the given one after', function () {
    expect(Plugin::getContainer())->toBeNull();

    $container = ($this->fullContainer)();
    Plugin::init($container);

    expect(Plugin::getContainer())->toBe($container);
});

// --- typed accessors: null / resolve / exception paths ---------------
it('returns null from an accessor when there is no container', function (string $method) {
    setPluginContainer(null);
    expect(Plugin::$method())->toBeNull();
})->with(pluginAccessorMethods());

it('resolves an accessor from the container', function (string $method, string $interface) {
    $service = $this->createMock($interface);
    setPluginContainer(new ReconcileFakeContainer([$interface => $service]));

    expect(Plugin::$method())->toBe($service);
})->with(pluginAccessorCases());

it('returns null from an accessor and logs when resolution throws', function (string $method) {
    setPluginContainer(new ThrowingContainer());
    expect(Plugin::$method())->toBeNull();
})->with(pluginAccessorMethods());

// --- availability probes ---------------------------------------------
it('reports every availability probe true when Unity is on the classpath', function () {
    // The bootstrap loads Unity's real interfaces as a sibling, so every
    // probe resolves true — which exercises the interface_exists chains.
    expect(Plugin::unityIsAvailable())->toBeTrue()
        ->and(Plugin::unityMembersAvailable())->toBeTrue()
        ->and(Plugin::unityGroupsAvailable())->toBeTrue()
        ->and(Plugin::unityPositionsAvailable())->toBeTrue()
        ->and(Plugin::unityContactsAvailable())->toBeTrue();
});

// --- registerServices / registerHandlers -----------------------------
it('binds and resolves every Reconcile service in registerServices()', function () {
    $container = ($this->fullContainer)();

    (new \ReflectionMethod(Plugin::class, 'registerServices'))->invoke(null, $container);

    foreach (
        [
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
        ] as $id
    ) {
        expect($container->get($id))->toBeInstanceOf($id, "$id should resolve");
    }
});

it('registers handler hooks on init() in admin', function () {
    Plugin::init(($this->fullContainer)());

    expect(has('wp_ajax_reconcile_import'))->not->toBeFalse('handler must wire wp_ajax_reconcile_import');
    // Six handlers each register one AJAX action; spot-check the rest.
    foreach (
        [
        'wp_ajax_reconcile_group_import',
        'wp_ajax_reconcile_position_import',
        'admin_post_reconcile_member_export',
        'admin_post_reconcile_group_export',
        'admin_post_reconcile_position_export',
        ] as $hook
    ) {
        expect(has($hook))->not->toBeFalse("handler must wire $hook");
    }
});

it('bails out of init() when not in admin', function () {
    WpState::$isAdmin = false;
    $container = ($this->fullContainer)();

    Plugin::init($container);

    // Container is stored, but no services/handlers wired.
    expect(Plugin::getContainer())->toBe($container)
        ->and(has('wp_ajax_reconcile_import'))->toBeFalse();
});

// --- menu registration ------------------------------------------------
it('bails out of registerMenus() when not in admin', function () {
    WpState::$isAdmin = false;

    Plugin::registerMenus();

    expect(has('admin_menu'))->toBeFalse();
});

it('wires the admin pages and menu hook in registerMenus()', function () {
    Plugin::registerMenus();

    expect(has('admin_menu'))->not->toBeFalse();

    // addMenuPages() then builds the top-level menu plus three submenus.
    Plugin::addMenuPages();
    $topLevel = array_column(
        array_filter(WpState::$menus, static fn (array $m): bool => $m['type'] === 'menu'),
        'slug'
    );
    expect($topLevel)->toContain('reconcile');
    $submenuSlugs = array_column(
        array_filter(WpState::$menus, static fn (array $m): bool => $m['type'] === 'submenu'),
        'slug'
    );
    expect($submenuSlugs)->toContain('reconcile')
        ->toContain('reconcile-groups')
        ->toContain('reconcile-positions');
});

// Plugin overrides the trait's default channel derivation, and with a real
// wp_log() the resolution memoises after the first call — so the override
// runs once and needs asserting on directly rather than incidentally.
it('logs through its own channel', function () {
    // HasLogger memoises the channel in a static that nothing resets
    // between tests, so whichever test logs first does the resolving.
    // Clear it here so the resolution — and Plugin's own logChannel()
    // override — actually runs where it is being asserted on.
    $loggerChannel = (new ReflectionClass(Plugin::class))->getProperty('loggerChannel');
    $loggerChannel->setValue(null, null);

    $channel = Plugin::log();

    expect($channel)->not->toBeNull()
        ->and($channel->channel)->toBe('reconcile');
});

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
