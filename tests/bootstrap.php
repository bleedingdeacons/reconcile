<?php

declare(strict_types=1);

// PHPUnit bootstrap.
//
// phpunit.xml has pointed at this file for a long time, but it did not exist
// and phpunit was not installed, so the suite could never run. That mattered:
// MemberImporterTest covers buildMember(), the call site that silently erased
// members' GDPR consent records on re-import.

require_once dirname(__DIR__) . '/vendor/autoload.php';

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

// Reconcile's importers reach for a handful of WordPress functions on their
// error paths. These are pure, so use the real semantics rather than making
// every test that touches a failure branch declare a mock.
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim(strip_tags($str));
    }
}

// GroupImporter (unlike MemberImporter, which persists through a repository)
// calls WordPress post functions directly. Stub the handful it uses so the
// persisting-path tests can run without a WordPress runtime.
if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        // WP_Error is not loaded in the unit suite; instanceof against an
        // undefined class is simply false, which is the behaviour we want.
        return $thing instanceof \WP_Error;
    }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post(array $postarr, bool $wp_error = false): int
    {
        // The create-path test seeds the returned post ID via this global.
        return (int) ($GLOBALS['__reconcile_test_wp_insert_post_returns'] ?? 1);
    }
}

if (!function_exists('wp_update_post')) {
    function wp_update_post(array $postarr, bool $wp_error = false): int
    {
        return (int) ($postarr['ID'] ?? 1);
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $postId, string $key, mixed $value): bool
    {
        return true;
    }
}

// do_action serves two suites: it is a no-op for unity/member_import (fired by
// MemberImporter, irrelevant to import logic) and captures unity/group_changing
// so GroupImporterChangingEventTest can assert the pre/post-write state. Kept
// here rather than in a test file so it is defined before any test runs,
// regardless of file load order.
if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        if (
            $hook === 'unity/group_changing'
            && count($args) === 2
            && class_exists(\Reconcile\Tests\Unit\Import\GroupImporterChangingEventTest::class)
        ) {
            \Reconcile\Tests\Unit\Import\GroupImporterChangingEventTest::$dispatchedGroupChangingEvents[] = [
                $args[0],
                $args[1],
            ];
        }
    }
}
