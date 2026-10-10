<?php

use App\Enums\CompressionType;
use App\Exceptions\ShellProcessFailed;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\ShellProcessor;
use App\Support\FilesystemSupport;

beforeEach(function () {
    config(['backup.encryption_key' => 'base64:'.base64_encode(random_bytes(32))]);

    $this->workingDirectory = sys_get_temp_dir().'/compress-command-output-'.uniqid();
    mkdir($this->workingDirectory, 0755, true);
});

afterEach(function () {
    FilesystemSupport::cleanupDirectory($this->workingDirectory);
});

test('a command\'s output is compressed into an archive that decompresses to the dump', function (CompressionType $type) {
    $compressor = (new CompressorFactory(new ShellProcessor))->make($type, 3, false);
    $dumpPath = $this->workingDirectory.'/dump.sql';

    $archive = $compressor->compressCommandOutput("printf 'CREATE TABLE t (id INT);'", $dumpPath);

    expect($archive)->toBe($compressor->getCompressedPath($dumpPath))
        ->and(file_exists($dumpPath))->toBeFalse();

    expect(file_get_contents($compressor->decompress($archive)))->toBe('CREATE TABLE t (id INT);');
})->with(CompressionType::cases());

test('a dump that fails partway fails the compression even though the compressor succeeded', function (CompressionType $type) {
    $compressor = (new CompressorFactory(new ShellProcessor))->make($type, 3, false);

    expect(fn () => $compressor->compressCommandOutput("printf 'partial'; exit 3", $this->workingDirectory.'/dump.sql'))
        ->toThrow(ShellProcessFailed::class);
})->with(CompressionType::cases());
