<?php

declare(strict_types=1);

// Pest configuration.
//
// Every test class in this suite extended wp-mocks' TestCase directly — there
// was never a plain-PHPUnit half, and no plugin wrapper of it. The importers,
// handlers and admin screens need Brain Monkey's hook layer, which only exists
// inside that TestCase's setUp(); its Mockery integration is also what
// verifies (and counts) the Mockery expectations most of these files assert
// through. So the whole directory is bound rather than a list of files.
//
// Were a pure-PHP test ever wanted off it, it would have to be carved out of
// this binding explicitly — and a test that needs WordPress but lands on
// Pest's default finds none of Brain Monkey's functions defined.

use BleedingDeacons\WpMocks\TestCase;

pest()->extend(TestCase::class)->in('.');

/**
 * Runs $render inside an output buffer and returns what it printed, with
 * Windows line endings normalised so assertions on multi-line markup hold on
 * any checkout.
 *
 * The admin screens echo their markup, so this is how their tests read it.
 * The buffer is closed in a finally, so a render that throws — wp_die() is a
 * WpDieException under the shared stubs — cannot leave it open and have
 * PHPUnit flag the test as risky.
 */
function captureOutput(callable $render): string
{
    ob_start();

    try {
        $render();
    } finally {
        $html = (string) ob_get_clean();
    }

    return str_replace("\r\n", "\n", $html);
}
