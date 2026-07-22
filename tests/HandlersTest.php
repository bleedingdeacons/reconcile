<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReconcileHandlerHalt;
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
 * The handlers' terminal WordPress calls (wp_send_json_*, wp_die) are stubbed
 * in the bootstrap to throw ReconcileHandlerHalt, so each guard branch can be
 * asserted on without the process exiting.
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
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__reconcile_test_can'] = true;
        $GLOBALS['__reconcile_test_nonce_valid'] = true;
        // Each resource handler reads a differently-named nonce field; seed
        // all of them so the nonce guard passes unless a test flips the
        // validity global.
        $_POST = [
            'reconcile_nonce' => 'good',
            'reconcile_group_nonce' => 'good',
            'reconcile_position_nonce' => 'good',
        ];
        $_GET = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        unset(
            $GLOBALS['__reconcile_test_can'],
            $GLOBALS['__reconcile_test_nonce_valid']
        );
        $_POST = [];
        $_GET = [];
        $_FILES = [];
        parent::tearDown();
    }

    /**
     * Run a handler method and return the ReconcileHandlerHalt it throws.
     */
    private function halt(callable $run): ReconcileHandlerHalt
    {
        try {
            $run();
        } catch (ReconcileHandlerHalt $halt) {
            return $halt;
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
        $GLOBALS['__reconcile_test_can'] = false;

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
        $GLOBALS['__reconcile_test_nonce_valid'] = false;
        $_POST['reconcile_nonce'] = 'bad';

        $halt = $this->halt(fn () => $handler->handleImport());

        $this->assertSame(403, $halt->statusCode);
    }

    /**
     * @test
     * @dataProvider importHandlers
     */
    public function import_rejects_a_missing_file(object $handler): void
    {
        $_POST['reconcile_nonce'] = 'good';

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
        $_POST['reconcile_nonce'] = 'good';
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
        $_POST['reconcile_nonce'] = 'good';
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

    // ─── export handler guards ──────────────────────────────────────

    /**
     * @return array<string, array{object}>
     */
    public static function exportHandlers(): array
    {
        return [
            'member'   => [new MemberExportHandler(new MemberExporter(null, null, null))],
            'group'    => [new GroupExportHandler(new GroupExporter(null))],
            'position' => [new PositionExportHandler(new PositionExporter(null))],
        ];
    }

    /**
     * @test
     * @dataProvider exportHandlers
     */
    public function export_denies_users_without_capability(object $handler): void
    {
        $GLOBALS['__reconcile_test_can'] = false;

        $halt = $this->halt(fn () => $handler->handleExport());

        $this->assertSame('wp_die', $halt->kind);
        $this->assertSame(403, $halt->statusCode);
    }

    /**
     * @test
     * @dataProvider exportHandlers
     */
    public function export_rejects_a_bad_nonce(object $handler): void
    {
        $GLOBALS['__reconcile_test_nonce_valid'] = false;
        $_GET['_wpnonce'] = 'bad';

        $halt = $this->halt(fn () => $handler->handleExport());

        $this->assertSame('wp_die', $halt->kind);
        $this->assertSame(403, $halt->statusCode);
    }

    /**
     * @test
     * @dataProvider exportHandlers
     */
    public function export_wp_dies_when_the_exporter_throws(object $handler): void
    {
        // Nonce/permission pass; the exporter was built with null repositories
        // so export() throws, and the handler converts that to wp_die().
        $_GET['_wpnonce'] = 'good';

        $halt = $this->halt(fn () => $handler->handleExport());

        $this->assertSame('wp_die', $halt->kind);
    }
}
