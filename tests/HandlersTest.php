<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use BleedingDeacons\WpMocks\Exceptions\JsonResponseException;
use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\TestCase;
use BleedingDeacons\WpMocks\WpState;
use Mockery;
use Reconcile\Core\OperationResult;
use Reconcile\Group\GroupExporter;
use Reconcile\Group\GroupExportHandler;
use Reconcile\Group\GroupImporter;
use Reconcile\Group\GroupImportHandler;
use Reconcile\Member\MemberExporter;
use Reconcile\Member\MemberExportHandler;
use Reconcile\Member\MemberImporter;
use Reconcile\Member\MemberImportHandler;
use Reconcile\Position\PositionExporter;
use Reconcile\Position\PositionExportHandler;
use Reconcile\Position\PositionImporter;
use Reconcile\Position\PositionImportHandler;

/**
 * Tests for the AJAX import handlers and admin-post export handlers.
 *
 * The handlers' terminal WordPress calls (wp_send_json_*, wp_die) throw rather
 * than exiting — JsonResponseException and WpDieException from wp-mocks — so
 * each guard branch can be asserted on without taking the process down.
 *
 * @covers \Reconcile\Member\MemberImportHandler
 * @covers \Reconcile\Group\GroupImportHandler
 * @covers \Reconcile\Position\PositionImportHandler
 * @covers \Reconcile\Member\MemberExportHandler
 * @covers \Reconcile\Group\GroupExportHandler
 * @covers \Reconcile\Position\PositionExportHandler
 */
class HandlersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // parent::setUp() clears WpState, where current_user_can() and
        // wp_verify_nonce() both default to allowing, so the happy path runs
        // without ceremony.
        // Each resource handler reads a differently-named nonce field; seed
        // all of them so the nonce guard passes unless a test flips the
        // validity global.
        // Each resource handler reads a differently-named nonce field and
        // verifies it against its own action; seed all of them with what
        // wp_create_nonce() would have produced so the nonce guard passes
        // unless a test deliberately supplies a bad one.
        $_POST = [
            'reconcile_nonce' => wp_create_nonce('reconcile_import'),
            'reconcile_group_nonce' => wp_create_nonce('reconcile_group_import'),
            'reconcile_position_nonce' => wp_create_nonce('reconcile_position_import'),
        ];
        $_GET = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_FILES = [];
        parent::tearDown();
    }

    /**
     * Run a handler method and describe how it terminated.
     *
     * wp-mocks throws two different exceptions here — JsonResponseException
     * for the AJAX handlers, WpDieException for the admin-post ones — and each
     * names its parts differently. Normalising them into one shape keeps every
     * assertion below reading the same way, which is what the local
     * ReconcileHandlerHalt used to do.
     */
    private function halt(callable $run): HandlerHalt
    {
        try {
            $run();
        } catch (JsonResponseException $json) {
            return new HandlerHalt(
                $json->success ? 'json_success' : 'json_error',
                $json->data,
                $json->status ?? 0
            );
        } catch (WpDieException $die) {
            return new HandlerHalt('wp_die', $die->getMessage(), $die->status ?? 0);
        }

        $this->fail('Expected the handler to halt, but it did not.');
    }

    /**
     * @return array<string, array{object}>
     */
    public static function importHandlers(): array
    {
        return [
            'member'   => [new MemberImportHandler(Mockery::mock(MemberImporter::class))],
            'group'    => [new GroupImportHandler(Mockery::mock(GroupImporter::class))],
            'position' => [new PositionImportHandler(Mockery::mock(PositionImporter::class))],
        ];
    }

    // ─── import handler guards ──────────────────────────────────────

    /**
     * @test
     * @dataProvider importHandlers
     */
    public function import_denies_users_without_capability(object $handler): void
    {
        WpState::$userCan = false;

        $halt = $this->halt(fn () => $handler->handleImport());

        $this->assertSame('json_error', $halt->kind);
        $this->assertSame(403, $halt->statusCode);
    }

    /**
     * @test
     * @dataProvider importHandlers
     */
    public function import_rejects_a_bad_nonce(object $handler): void
    {
        // The provider runs this for all three handlers, each reading its own
        // nonce field, so all three have to be wrong.
        $_POST['reconcile_nonce'] = 'not-the-right-nonce';
        $_POST['reconcile_group_nonce'] = 'not-the-right-nonce';
        $_POST['reconcile_position_nonce'] = 'not-the-right-nonce';

        $halt = $this->halt(fn () => $handler->handleImport());

        $this->assertSame(403, $halt->statusCode);
    }

    /**
     * @test
     * @dataProvider importHandlers
     */
    public function import_rejects_a_missing_file(object $handler): void
    {
        $_POST['reconcile_nonce'] = wp_create_nonce('reconcile_import');

        $halt = $this->halt(fn () => $handler->handleImport());

        $this->assertSame('json_error', $halt->kind);
        $this->assertSame(400, $halt->statusCode);
    }

    /**
     * @test
     * @dataProvider importHandlers
     */
    public function import_rejects_an_unsupported_extension(object $handler): void
    {
        $_POST['reconcile_nonce'] = wp_create_nonce('reconcile_import');
        $_FILES['import_file'] = [
            'name' => 'data.txt',
            'error' => UPLOAD_ERR_OK,
            'size' => 10,
            'tmp_name' => '/tmp/whatever',
        ];

        $halt = $this->halt(fn () => $handler->handleImport());

        $this->assertSame(400, $halt->statusCode);
    }

    /**
     * @test
     * @dataProvider importHandlers
     */
    public function import_rejects_a_file_that_was_not_actually_uploaded(object $handler): void
    {
        // A .csv extension gets past the extension check, but ImportTempDir
        // rejects it because tmp_name is not a genuine uploaded file.
        $_POST['reconcile_nonce'] = wp_create_nonce('reconcile_import');
        $_FILES['import_file'] = [
            'name' => 'data.csv',
            'error' => UPLOAD_ERR_OK,
            'size' => 10,
            'tmp_name' => sys_get_temp_dir() . '/not-an-upload.csv',
        ];

        $halt = $this->halt(fn () => $handler->handleImport());

        $this->assertSame(400, $halt->statusCode);
    }

    // ─── import handler happy paths ─────────────────────────────────

    /**
     * @return array<string, array{class-string, class-string, string}>
     */
    public static function importHappyCases(): array
    {
        return [
            'member'   => [MemberImportHandler::class, MemberImporter::class, 'reconcile_nonce'],
            'group'    => [GroupImportHandler::class, GroupImporter::class, 'reconcile_group_nonce'],
            'position' => [PositionImportHandler::class, PositionImporter::class, 'reconcile_position_nonce'],
        ];
    }

    private function uploadCsv(): void
    {
        $src = tempnam(sys_get_temp_dir(), 'up_') . '.csv';
        file_put_contents($src, "a,b\n1,2\n");
        $_FILES['import_file'] = [
            'name' => 'data.csv',
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($src),
            'tmp_name' => $src,
        ];
    }

    /**
     * @test
     * @dataProvider importHappyCases
     */
    public function import_reports_success_for_a_clean_run(string $handlerClass, string $importerClass, string $nonceKey): void
    {
        $result = new OperationResult();
        $result->setTotalRows(1);
        $result->incrementCreated();

        $importer = Mockery::mock($importerClass);
        $importer->shouldReceive('import')->once()->andReturn($result);

        $handler = new $handlerClass($importer);
        $this->uploadCsv();

        $halt = $this->halt(fn () => $handler->handleImport());

        $this->assertSame('json_success', $halt->kind);
    }

    /**
     * @test
     * @dataProvider importHappyCases
     */
    public function import_reports_422_when_the_result_has_errors(string $handlerClass, string $importerClass, string $nonceKey): void
    {
        $result = new OperationResult();
        $result->addError('Missing required columns');

        $importer = Mockery::mock($importerClass);
        $importer->shouldReceive('import')->once()->andReturn($result);

        $handler = new $handlerClass($importer);
        $this->uploadCsv();

        $halt = $this->halt(fn () => $handler->handleImport());

        $this->assertSame('json_error', $halt->kind);
        $this->assertSame(422, $halt->statusCode);
    }

    /**
     * @test
     * @dataProvider importHappyCases
     */
    public function import_reports_500_when_the_importer_throws(string $handlerClass, string $importerClass, string $nonceKey): void
    {
        $importer = Mockery::mock($importerClass);
        $importer->shouldReceive('import')->once()->andThrow(new \RuntimeException('kaboom'));

        $handler = new $handlerClass($importer);
        $this->uploadCsv();

        $halt = $this->halt(fn () => $handler->handleImport());

        $this->assertSame('json_error', $halt->kind);
        $this->assertSame(500, $halt->statusCode);
    }

    /**
     * @test
     * @dataProvider importHappyCases
     */
    public function import_logs_result_warnings_and_still_succeeds(string $handlerClass, string $importerClass, string $nonceKey): void
    {
        $result = new OperationResult();
        $result->setTotalRows(2);
        $result->incrementCreated();
        $result->addWarning('Row 2 skipped: duplicate anonymous name');

        $importer = Mockery::mock($importerClass);
        $importer->shouldReceive('import')->once()->andReturn($result);

        $handler = new $handlerClass($importer);
        $this->uploadCsv();

        // A result with warnings but no errors still succeeds; the warnings
        // loop runs on the way out.
        $halt = $this->halt(fn () => $handler->handleImport());
        $this->assertSame('json_success', $halt->kind);
    }

    /**
     * @return array<string, array{object, int, string}>
     */
    public static function uploadErrorMessages(): array
    {
        $codes = [
            [UPLOAD_ERR_INI_SIZE, 'server upload size limit'],
            [UPLOAD_ERR_FORM_SIZE, 'form upload size limit'],
            [UPLOAD_ERR_PARTIAL, 'partially uploaded'],
            [UPLOAD_ERR_NO_TMP_DIR, 'temporary folder'],
            [UPLOAD_ERR_CANT_WRITE, 'write file to disk'],
            [UPLOAD_ERR_EXTENSION, 'extension stopped'],
            [999, 'Unknown error'],
        ];
        $handlers = [
            'member'   => new MemberImportHandler(Mockery::mock(MemberImporter::class)),
            'group'    => new GroupImportHandler(Mockery::mock(GroupImporter::class)),
            'position' => new PositionImportHandler(Mockery::mock(PositionImporter::class)),
        ];

        $cases = [];
        foreach ($handlers as $hLabel => $handler) {
            foreach ($codes as [$code, $fragment]) {
                $cases["$hLabel: $fragment"] = [$handler, $code, $fragment];
            }
        }
        return $cases;
    }

    /**
     * @test
     * @dataProvider uploadErrorMessages
     */
    public function import_reports_the_specific_upload_error_message(object $handler, int $code, string $fragment): void
    {
        // A non-OK upload error code is mapped to a human message before the
        // 400 response — this pins every arm of uploadErrorMessage().
        $_FILES['import_file'] = [
            'name' => 'data.csv',
            'error' => $code,
            'size' => 0,
            'tmp_name' => '',
        ];

        $halt = $this->halt(fn () => $handler->handleImport());

        $this->assertSame('json_error', $halt->kind);
        $this->assertSame(400, $halt->statusCode);
        $this->assertStringContainsString($fragment, $halt->payload['message'] ?? '');
    }

    // ─── export handler guards ──────────────────────────────────────

    /**
     * @return array<string, array{object}>
     */
    /**
     * Each export handler verifies _wpnonce against its own action, so the
     * provider carries that action alongside the handler: one $_GET value
     * cannot satisfy all three at once.
     */
    public static function exportHandlers(): array
    {
        return [
            'member'   => [new MemberExportHandler(new MemberExporter(null, null, null)), 'reconcile_member_export'],
            'group'    => [new GroupExportHandler(new GroupExporter(null)), 'reconcile_group_export'],
            'position' => [new PositionExportHandler(new PositionExporter(null)), 'reconcile_position_export'],
        ];
    }

    /**
     * @test
     * @dataProvider exportHandlers
     */
    public function export_denies_users_without_capability(object $handler, string $nonceAction): void
    {
        WpState::$userCan = false;
        $_GET['_wpnonce'] = wp_create_nonce($nonceAction);

        $halt = $this->halt(fn () => $handler->handleExport());

        $this->assertSame('wp_die', $halt->kind);
        $this->assertSame(403, $halt->statusCode);
    }

    /**
     * @test
     * @dataProvider exportHandlers
     */
    public function export_rejects_a_bad_nonce(object $handler, string $nonceAction): void
    {
        $_GET['_wpnonce'] = 'not-the-right-nonce';

        $halt = $this->halt(fn () => $handler->handleExport());

        $this->assertSame('wp_die', $halt->kind);
        $this->assertSame(403, $halt->statusCode);
    }

    /**
     * @test
     * @dataProvider exportHandlers
     */
    public function export_wp_dies_when_the_exporter_throws(object $handler, string $nonceAction): void
    {
        // Nonce/permission pass; the exporter was built with null repositories
        // so export() throws, and the handler converts that to wp_die().
        $_GET['_wpnonce'] = wp_create_nonce($nonceAction);

        $halt = $this->halt(fn () => $handler->handleExport());

        $this->assertSame('wp_die', $halt->kind);
    }
}

/**
 * One shape for "the handler stopped, and here is how".
 *
 * wp-mocks throws JsonResponseException for the AJAX handlers and
 * WpDieException for the admin-post ones. Both carry what they were called
 * with, but under different names; this flattens the two so the assertions
 * above do not have to care which kind of endpoint they are looking at.
 */
final class HandlerHalt
{
    public function __construct(
        public readonly string $kind,
        public readonly mixed $payload = null,
        public readonly int $statusCode = 0
    ) {
    }
}
