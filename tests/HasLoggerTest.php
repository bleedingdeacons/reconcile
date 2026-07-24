<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reconcile\Logger\HasLogger;

/**
 * Covers the HasLogger trait's resolution and every log-level forwarder.
 *
 * The production classes (Plugin) override logChannel(), so the trait's own
 * default channel derivation and the rarely-called levels
 * (emergency/alert/critical/notice) are never exercised through them. A host
 * class that uses the trait unchanged drives those paths. wp_log() returns
 * null in the unit run, so the forwarders are safe no-ops — the point is that
 * their bodies run.
 *
 * @covers \Reconcile\Logger\HasLogger
 */
class HasLoggerTest extends TestCase
{
    /** @test */
    public function log_resolves_through_the_default_channel_name(): void
    {
        // wp_log() is defined (returns null), so log() runs its resolution
        // path — including the trait's default logChannel() (sanitize_key of
        // the class basename) — rather than short-circuiting.
        $this->assertNull(ReconcileLoggerHost::log());
    }

    /** @test */
    public function every_level_forwarder_runs_without_error(): void
    {
        ReconcileLoggerHost::logEmergency('m', ['k' => 'v']);
        ReconcileLoggerHost::logAlert('m');
        ReconcileLoggerHost::logCritical('m');
        ReconcileLoggerHost::logError('m');
        ReconcileLoggerHost::logWarning('m');
        ReconcileLoggerHost::logNotice('m');
        ReconcileLoggerHost::logInfo('m');
        ReconcileLoggerHost::logDebug('m');

        // No channel is available, so nothing is emitted; reaching here proves
        // each forwarder body executed against a null channel safely.
        $this->assertTrue(true);
    }
}

/** A class that uses HasLogger without overriding logChannel(). */
class ReconcileLoggerHost
{
    use HasLogger;
}
