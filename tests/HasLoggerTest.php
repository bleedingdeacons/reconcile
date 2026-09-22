<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use BleedingDeacons\WpMocks\WpState;
use Reconcile\Logger\HasLogger;

/*
 * Covers the HasLogger trait's resolution and every log-level forwarder.
 *
 * The production classes (Plugin) override logChannel(), so the trait's own
 * default channel derivation and the rarely-called levels
 * (emergency/alert/critical/notice) are never exercised through them. A host
 * class that uses the trait unchanged drives those paths. wp-mocks' `sentinel`
 * group supplies wp_log(), so the forwarders resolve a real channel and what
 * they emit lands in WpState::$logs where it can be asserted on.
 */

covers(HasLogger::class);

it('resolves log() through the default channel name', function () {
    // The trait's default logChannel() is sanitize_key() of the class
    // basename, and the channel is memoised, so the same object comes
    // back a second time rather than being resolved again.
    $channel = ReconcileLoggerHost::log();

    expect($channel)->not->toBeNull()
        ->and($channel->channel)->toBe('reconcileloggerhost')
        ->and(ReconcileLoggerHost::log())->toBe($channel);
});

it('runs every level forwarder without error', function () {
    ReconcileLoggerHost::logEmergency('m', ['k' => 'v']);
    ReconcileLoggerHost::logAlert('m');
    ReconcileLoggerHost::logCritical('m');
    ReconcileLoggerHost::logError('m');
    ReconcileLoggerHost::logWarning('m');
    ReconcileLoggerHost::logNotice('m');
    ReconcileLoggerHost::logInfo('m');
    ReconcileLoggerHost::logDebug('m');

    $levels = array_column(
        array_filter(WpState::$logs, static fn (array $l): bool => $l[0] === 'reconcileloggerhost'),
        1
    );

    expect($levels)->toBe(['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug']);
});

/** A class that uses HasLogger without overriding logChannel(). */
class ReconcileLoggerHost
{
    use HasLogger;
}
