<?php

use App\Enums\CompressionType;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Compressors\EncryptedCompressor;
use Tests\Support\TestShellProcessor;

beforeEach(function () {
    $this->shellProcessor = new TestShellProcessor;
    config(['backup.encryption_key' => 'base64:dGVzdGtleXRlc3RrZXl0ZXN0a2V5dGVzdGtleXRlc3Q=']);
});

test('encrypted command generation', function () {
    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make(CompressionType::ENCRYPTED, 6);

    expect($compressor)->toBeInstanceOf(EncryptedCompressor::class)
        ->and($compressor->getExtension())->toBe('7z')
        ->and($compressor->getCompressCommandLine('/path/to/dump.sql'))->toContain('7z a -t7z -mx=6 -mhe=on')
        ->and($compressor->getDecompressCommandLine('/path/to/dump.sql.7z'))->toContain('7z x -y');
});

test('encrypted compression level is clamped to valid range', function (int $inputLevel, int $expectedLevel) {
    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make(CompressionType::ENCRYPTED, $inputLevel);
    $command = $compressor->getCompressCommandLine('/path/to/dump.sql');

    expect($command)->toContain("-mx={$expectedLevel}");
})->with([
    'min' => [0, 1],
    'max' => [10, 9],
]);

test('encrypted compressor includes password in commands when provided', function () {
    $compressor = new EncryptedCompressor($this->shellProcessor, 6, 'secret123');

    expect($compressor->getCompressCommandLine('/path/to/dump.sql'))->toContain("-p'secret123'")
        ->and($compressor->getDecompressCommandLine('/path/to/dump.sql.7z'))->toContain("-p'secret123'");
});

test('encrypted compressor omits password when not provided', function () {
    $compressor = new EncryptedCompressor($this->shellProcessor, 6, null);

    expect($compressor->getCompressCommandLine('/path/to/dump.sql'))->not->toContain('-p');
});

test('encrypted compressor executes compress and returns correct path', function () {
    $testFile = '/tmp/test_dump.sql';
    file_put_contents($testFile, 'test data');

    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make(CompressionType::ENCRYPTED);
    $compressedPath = $compressor->compress($testFile);

    expect($compressedPath)->toEndWith('.7z')
        ->and(file_exists($compressedPath))->toBeTrue();

    unlink($compressedPath);
});
