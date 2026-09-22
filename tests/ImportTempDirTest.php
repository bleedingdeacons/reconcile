<?php

declare(strict_types=1);

namespace Reconcile\Tests\Unit;

use Reconcile\Core\ImportTempDir;
use RuntimeException;

/*
 * Tests for ImportTempDir.
 *
 * The upload builtins are overridden in the Reconcile\Core namespace (see
 * tests/CoreFunctionOverrides.php) so the accept() path runs against ordinary
 * temp files.
 */

covers(ImportTempDir::class);

beforeEach(function () {
    // Paths to clean up after each test.
    $this->cleanup = [];

    // Write $contents to a fresh temp file with the given extension, queued
    // for cleanup.
    $this->tempFile = function (string $extension, string $contents): string {
        $path = tempnam(sys_get_temp_dir(), 'itd_') . '.' . $extension;
        file_put_contents($path, $contents);
        $this->cleanup[] = $path;
        return $path;
    };
});

afterEach(function () {
    unset($GLOBALS['__reconcile_test_is_uploaded']);
    foreach ($this->cleanup as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    $this->cleanup = [];
});

it('returns a writable directory from path()', function () {
    $dir = ImportTempDir::path();

    expect($dir)->toBeDirectory()
        ->toBeWritableDirectory();
});

it('moves a valid CSV upload', function () {
    $source = ($this->tempFile)('csv', "a,b,c\n1,2,3\n");

    $target = ImportTempDir::accept([
        'name' => 'members.csv',
        'tmp_name' => $source,
        'size' => filesize($source),
    ]);
    $this->cleanup[] = $target;

    expect($target)->toBeFile()
        ->toContain('members')
        // The source was moved, not copied.
        ->and($source)->not->toBeFile();

    ImportTempDir::cleanup($target);
    expect($target)->not->toBeFile();
});

it('rejects an empty tmp_name', function () {
    ImportTempDir::accept(['name' => 'x.csv', 'tmp_name' => '']);
})->throws(RuntimeException::class);

it('rejects a file that was not uploaded', function () {
    $GLOBALS['__reconcile_test_is_uploaded'] = false;
    $source = ($this->tempFile)('csv', "a,b\n1,2\n");

    ImportTempDir::accept(['name' => 'x.csv', 'tmp_name' => $source, 'size' => 10]);
})->throws(RuntimeException::class);

it('rejects an empty file', function () {
    $source = ($this->tempFile)('csv', '');

    ImportTempDir::accept(['name' => 'x.csv', 'tmp_name' => $source, 'size' => 0]);
})->throws(RuntimeException::class, 'empty');

it('rejects an oversized file', function () {
    $source = ($this->tempFile)('csv', "a,b\n1,2\n");

    ImportTempDir::accept([
        'name' => 'x.csv',
        'tmp_name' => $source,
        'size' => ImportTempDir::MAX_UPLOAD_BYTES + 1,
    ]);
})->throws(RuntimeException::class, 'upload limit');

it('rejects an extension with no allowed MIME types', function () {
    // An extension that is neither csv nor xlsx has an empty allow-list, so
    // the MIME check rejects it regardless of what libmagic sniffs. (The
    // handler blocks such extensions earlier; this pins the defence in
    // depth inside accept().)
    $source = ($this->tempFile)('txt', "just some text\n");

    ImportTempDir::accept(['name' => 'notes.txt', 'tmp_name' => $source, 'size' => filesize($source)]);
})->skip(! extension_loaded('fileinfo'), 'Requires the fileinfo extension.')->throws(RuntimeException::class);

it('is safe to clean up a missing path', function () {
    // Should not error.
    expect(fn () => ImportTempDir::cleanup('/no/such/file'))->not->toThrow(\Throwable::class);
});

it('rejects content that does not match the extension', function () {
    // GIF bytes wearing a .csv extension. finfo sniffs image/gif, which is
    // not in the CSV allow-list, so the MIME-vs-extension guard rejects it
    // — the defence against an image/HTML file renamed to .csv.
    $source = ($this->tempFile)('csv', "GIF89a" . str_repeat("\x00", 32));

    ImportTempDir::accept(['name' => 'evil.csv', 'tmp_name' => $source, 'size' => filesize($source)]);
})->skip(! extension_loaded('fileinfo'), 'Requires the fileinfo extension.')->throws(RuntimeException::class, 'does not match');

it('moves a valid XLSX upload', function () {
    // XLSX is a ZIP archive; "PK\x03\x04" is the ZIP local-file header, so
    // finfo sniffs application/zip — accepted for the .xlsx extension.
    $source = ($this->tempFile)('xlsx', "PK\x03\x04" . str_repeat("\x00", 64));

    $target = ImportTempDir::accept([
        'name' => 'members.xlsx',
        'tmp_name' => $source,
        'size' => filesize($source),
    ]);
    $this->cleanup[] = $target;

    expect($target)->toBeFile()
        ->toEndWith('.xlsx');
    ImportTempDir::cleanup($target);
})->skip(! extension_loaded('fileinfo'), 'Requires the fileinfo extension.');

it('writes the deny and index files when hardening', function () {
    $dir = sys_get_temp_dir() . '/reconcile-harden-' . uniqid() . '/';
    mkdir($dir, 0777, true);

    (new \ReflectionMethod(ImportTempDir::class, 'harden'))->invoke(null, $dir);

    expect($dir . '.htaccess')->toBeFile()
        ->and((string) file_get_contents($dir . '.htaccess'))->toContain('Require all denied')
        ->and($dir . 'index.php')->toBeFile()
        ->and($dir . 'index.html')->toBeFile();

    // Idempotent: a second call over existing files must not error.
    (new \ReflectionMethod(ImportTempDir::class, 'harden'))->invoke(null, $dir);

    foreach (['.htaccess', 'index.php', 'index.html'] as $f) {
        @unlink($dir . $f);
    }
    @rmdir($dir);
});
