<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reconcile\Core\ImportTempDir;
use RuntimeException;

/**
 * Tests for ImportTempDir.
 *
 * The upload builtins are overridden in the Reconcile\Core namespace (see
 * tests/CoreFunctionOverrides.php) so the accept() path runs against ordinary
 * temp files.
 *
 * @covers \Reconcile\Core\ImportTempDir
 */
class ImportTempDirTest extends TestCase
{
    /** @var string[] Paths to clean up after each test. */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['__reconcile_test_is_uploaded']);
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->cleanup = [];
        parent::tearDown();
    }

    private function tempFile(string $extension, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'itd_') . '.' . $extension;
        file_put_contents($path, $contents);
        $this->cleanup[] = $path;
        return $path;
    }

    /**
     * @test
     */
    public function path_returns_a_writable_directory(): void
    {
        $dir = ImportTempDir::path();

        $this->assertDirectoryExists($dir);
        $this->assertDirectoryIsWritable($dir);
    }

    /**
     * @test
     */
    public function accept_moves_a_valid_csv_upload(): void
    {
        $source = $this->tempFile('csv', "a,b,c\n1,2,3\n");

        $target = ImportTempDir::accept([
            'name' => 'members.csv',
            'tmp_name' => $source,
            'size' => filesize($source),
        ]);
        $this->cleanup[] = $target;

        $this->assertFileExists($target);
        $this->assertStringContainsString('members', $target);
        // The source was moved, not copied.
        $this->assertFileDoesNotExist($source);

        ImportTempDir::cleanup($target);
        $this->assertFileDoesNotExist($target);
    }

    /**
     * @test
     */
    public function accept_rejects_an_empty_tmp_name(): void
    {
        $this->expectException(RuntimeException::class);
        ImportTempDir::accept(['name' => 'x.csv', 'tmp_name' => '']);
    }

    /**
     * @test
     */
    public function accept_rejects_a_file_that_was_not_uploaded(): void
    {
        $GLOBALS['__reconcile_test_is_uploaded'] = false;
        $source = $this->tempFile('csv', "a,b\n1,2\n");

        $this->expectException(RuntimeException::class);
        ImportTempDir::accept(['name' => 'x.csv', 'tmp_name' => $source, 'size' => 10]);
    }

    /**
     * @test
     */
    public function accept_rejects_an_empty_file(): void
    {
        $source = $this->tempFile('csv', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty');
        ImportTempDir::accept(['name' => 'x.csv', 'tmp_name' => $source, 'size' => 0]);
    }

    /**
     * @test
     */
    public function accept_rejects_an_oversized_file(): void
    {
        $source = $this->tempFile('csv', "a,b\n1,2\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('upload limit');
        ImportTempDir::accept([
            'name' => 'x.csv',
            'tmp_name' => $source,
            'size' => ImportTempDir::MAX_UPLOAD_BYTES + 1,
        ]);
    }

    /**
     * @test
     * @requires extension fileinfo
     */
    public function accept_rejects_an_extension_with_no_allowed_mime_types(): void
    {
        // An extension that is neither csv nor xlsx has an empty allow-list, so
        // the MIME check rejects it regardless of what libmagic sniffs. (The
        // handler blocks such extensions earlier; this pins the defence in
        // depth inside accept().)
        $source = $this->tempFile('txt', "just some text\n");

        $this->expectException(RuntimeException::class);
        ImportTempDir::accept(['name' => 'notes.txt', 'tmp_name' => $source, 'size' => filesize($source)]);
    }

    /**
     * @test
     */
    public function cleanup_is_safe_to_call_on_a_missing_path(): void
    {
        // Should not error.
        ImportTempDir::cleanup('/no/such/file');
        $this->assertTrue(true);
    }
}
