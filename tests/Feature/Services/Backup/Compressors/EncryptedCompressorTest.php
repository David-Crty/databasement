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
    $compressor = new EncryptedCompressor($this->shellProcessor, 6, 'secret123');

    expect($compressor->getExtension())->toBe('7z')
        ->and($compressor->getCompressCommandLine('/path/to/dump.sql'))->toBe("7z a -t7z -mx=6 -mhe=on -p'secret123' '/path/to/dump.sql.7z' '/path/to/dump.sql'")
        ->and($compressor->getDecompressCommandLine('/path/to/dump.sql.7z'))->toBe("7z x -y -o'/path/to' -p'secret123' '/path/to/dump.sql.7z'");
});

test('encrypted compression level is clamped to valid range', function (int $inputLevel, int $expectedLevel) {
    $compressor = new EncryptedCompressor($this->shellProcessor, $inputLevel);

    expect($compressor->getCompressCommandLine('/path/to/dump.sql'))->toBe("7z a -t7z -mx={$expectedLevel} -mhe=on '/path/to/dump.sql.7z' '/path/to/dump.sql'");
})->with([
    'min' => [0, 1],
    'max' => [10, 9],
]);

test('encrypted compressor omits password when not provided', function () {
    $compressor = new EncryptedCompressor($this->shellProcessor, 6, null);

    expect($compressor->getCompressCommandLine('/path/to/dump.sql'))->toBe("7z a -t7z -mx=6 -mhe=on '/path/to/dump.sql.7z' '/path/to/dump.sql'")
        ->and($compressor->getDecompressCommandLine('/path/to/dump.sql.7z'))->toBe("7z x -y -o'/path/to' '/path/to/dump.sql.7z'");
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
