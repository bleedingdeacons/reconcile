<?php

declare(strict_types=1);

// PHPUnit bootstrap.
//
// WordPress stand-ins come from bleedingdeacons/wp-mocks, shared across the
// plugin suite. Its bootstrap loads Patchwork before anything patchable, so
// anything below that defines WordPress functions of its own — here, the
// namespace-local upload builtins — must stay after the Bootstrap::load()
// call, not before it.
//
// Reconcile does not use ACF and has no REST surface, so neither of those
// groups is loaded.
//
// The `sentinel` group is, because HasLogger resolves its channel through
// wp_log() and skips the whole resolution when that function is absent. The
// old bootstrap defined a wp_log() returning null for exactly this reason —
// so the trait's resolution path runs rather than being stepped over — and
// the shared stub does the same job, recording what was logged into
// WpState::$logs where a test can assert on it.

use BleedingDeacons\WpMocks\Bootstrap;
use BleedingDeacons\WpMocks\WpState;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Bootstrap::load(['wordpress', 'sentinel']);

// Makes plugins_url()/plugin_dir_url() answer with Reconcile's own path.
WpState::$pluginSlug = 'reconcile';

if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

// reconcile.php defines these from the plugin header at runtime; the Admin
// screens read both when enqueueing their assets. They are defined here rather
// than in a test because a define() only takes the first time it runs — set in
// setUp(), the value would depend on which test happened to run first.
if (!defined('RECONCILE_VERSION')) {
    define('RECONCILE_VERSION', '0.0.0-test');
}

if (!defined('RECONCILE_PLUGIN_URL')) {
    define('RECONCILE_PLUGIN_URL', 'https://example.test/wp-content/plugins/reconcile/');
}

// Unity's interfaces are loaded from the real plugin in the sibling directory,
// which is exactly what WordPress loads at runtime. Reading the real files
// rather than a hand-copy means a change to Unity's contract fails these tests
// immediately instead of going unnoticed until production.
//
// Deliberately not a Composer path repository: that would be a hard
// require-dev, and `composer install` — a CI gate — fails outright when
// ../unity is absent. CI checks Unity out as a sibling before installing.
$unitySrc = dirname(__DIR__, 2) . '/unity/src';

if (!is_dir($unitySrc)) {
    fwrite(STDERR, PHP_EOL . 'ERROR: Unity plugin source not found at ' . $unitySrc . PHP_EOL
        . 'Reconcile is built on Unity\'s interfaces, so the Unity plugin must be' . PHP_EOL
        . 'checked out as a sibling directory for this suite to run.' . PHP_EOL . PHP_EOL);
    exit(1);
}

spl_autoload_register(static function (string $class) use ($unitySrc): void {
    if (!str_starts_with($class, 'Unity\\')) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen('Unity\\')));
    $file     = $unitySrc . '/' . $relative . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

// Namespace-local overrides of the upload builtins ImportTempDir uses. These
// are PHP functions rather than WordPress ones, so no shared package covers
// them — and like any other definition they have to come after
// Bootstrap::load().
require_once __DIR__ . '/CoreFunctionOverrides.php';
