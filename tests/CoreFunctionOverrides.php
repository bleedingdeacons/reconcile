<?php

declare(strict_types=1);

/**
 * Namespace-local overrides of the two PHP upload builtins that
 * ImportTempDir::accept() calls unqualified.
 *
 * PHP resolves an unqualified call inside a namespace to that namespace
 * first, so defining Reconcile\Core\is_uploaded_file() and
 * Reconcile\Core\move_uploaded_file() lets the upload-acceptance path run
 * against ordinary temp files in the unit suite — there is no real HTTP
 * upload to satisfy the genuine builtins.
 *
 * Behaviour is controlled by globals so tests can force the "not an
 * uploaded file" rejection branch as well as the success path.
 */

namespace Reconcile\Core;

if (!function_exists('Reconcile\\Core\\is_uploaded_file')) {
    function is_uploaded_file(string $filename): bool
    {
        if (array_key_exists('__reconcile_test_is_uploaded', $GLOBALS)) {
            return (bool) $GLOBALS['__reconcile_test_is_uploaded'];
        }

        return \is_file($filename);
    }
}

if (!function_exists('Reconcile\\Core\\move_uploaded_file')) {
    function move_uploaded_file(string $from, string $to): bool
    {
        return \rename($from, $to);
    }
}
