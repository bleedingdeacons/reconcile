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
// Only the `wordpress` group is loaded. Reconcile does not use ACF, has no
// REST surface, and its HasLogger is written to no-op when wp_log() is absent,
// which is the branch these tests run.

use BleedingDeacons\WpMocks\Bootstrap;
use BleedingDeacons\WpMocks\WpState;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Bootstrap::load(['wordpress']);

// Makes plugins_url()/plugin_dir_url() answer with Reconcile's own path.
WpState::$pluginSlug = 'reconcile';

if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
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
