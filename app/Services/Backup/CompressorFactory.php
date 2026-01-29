<?php

namespace App\Services\Backup;

use App\Enums\CompressionType;

class CompressorFactory
{
    public function __construct(
        private readonly ShellProcessor $shellProcessor
    ) {}

    /**
     * Create a compressor instance based on configuration.
     */
    public function make(?CompressionType $type = null, ?int $level = null): CompressorInterface
    {
        $type = $type ?? CompressionType::from(config('backup.compression'));
        $level = $level ?? (int) config('backup.compression_level');

        return match ($type) {
            CompressionType::GZIP => new GzipCompressor($this->shellProcessor, $level),
            CompressionType::ZSTD => new ZstdCompressor($this->shellProcessor, $level),
            CompressionType::ENCRYPTED => new EncryptedCompressor(
                $this->shellProcessor,
                $level,
                $this->getEncryptionKey()
            ),
        };
    }

    /**
     * Get the encryption key from config, stripping the base64: prefix if present.
     */
    private function getEncryptionKey(): string
    {
        $key = config('backup.encryption_key');

        if (empty($key)) {
            throw new \RuntimeException('Backup encryption key is not configured');
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false || $decoded === '') {
                throw new \RuntimeException('Backup encryption key is not valid base64');
            }
            // Use a CLI-safe representation for 7z password
            $key = bin2hex($decoded);
        }

        if ($key === '') {
            throw new \RuntimeException('Backup encryption key is empty');
        }

        return $key;
    }
}
