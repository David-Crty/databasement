<?php

namespace App\Enums;

enum VolumeType: string
{
    case LOCAL = 'local';
    case S3 = 's3';
    case SFTP = 'sftp';
    case FTP = 'ftp';

    public function label(): string
    {
        return match ($this) {
            self::LOCAL => 'Local Storage',
            self::S3 => 'Amazon S3',
            self::SFTP => 'SFTP / SSH',
            self::FTP => 'FTP',
        };
    }

    /**
     * Get the Heroicon name for this volume type.
     */
    public function icon(): string
    {
        return match ($this) {
            self::LOCAL => 'o-folder',
            self::S3 => 'o-cloud',
            self::SFTP => 'o-lock-closed',
            self::FTP => 'o-arrow-up-tray',
        };
    }

    /**
     * Get the Livewire form property name for this volume type's config.
     */
    public function configPropertyName(): string
    {
        return match ($this) {
            self::LOCAL => 'localConfig',
            self::S3 => 's3Config',
            self::SFTP => 'sftpConfig',
            self::FTP => 'ftpConfig',
        };
    }

    /**
     * Get the config connector class for this volume type.
     *
     * @return class-string<\App\Livewire\Volume\Connectors\LocalConfig|\App\Livewire\Volume\Connectors\S3Config|\App\Livewire\Volume\Connectors\SftpConfig|\App\Livewire\Volume\Connectors\FtpConfig>
     */
    public function configClass(): string
    {
        return match ($this) {
            self::LOCAL => \App\Livewire\Volume\Connectors\LocalConfig::class,
            self::S3 => \App\Livewire\Volume\Connectors\S3Config::class,
            self::SFTP => \App\Livewire\Volume\Connectors\SftpConfig::class,
            self::FTP => \App\Livewire\Volume\Connectors\FtpConfig::class,
        };
    }

    /**
     * Get the validation rules for this volume type's config.
     *
     * @return array<string, mixed>
     */
    public function configRules(): array
    {
        return $this->configClass()::rules($this->configPropertyName());
    }

    /**
     * Fields that should be encrypted when storing in the database.
     *
     * @return string[]
     */
    public function sensitiveFields(): array
    {
        return match ($this) {
            self::LOCAL, self::S3 => [],
            self::SFTP, self::FTP => ['password'],
        };
    }

    /**
     * Mask sensitive fields by setting them to empty strings.
     * Used to prevent sensitive data from being serialized to the browser.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function maskSensitiveFields(array $config): array
    {
        foreach ($this->sensitiveFields() as $field) {
            if (isset($config[$field])) {
                $config[$field] = '';
            }
        }

        return $config;
    }

    /**
     * Merge sensitive fields from persisted config when form values are empty.
     * Used during edit to preserve existing values when user doesn't provide new ones.
     *
     * @param  array<string, mixed>  $formConfig
     * @param  array<string, mixed>  $persistedConfig
     * @return array<string, mixed>
     */
    public function mergeSensitiveFromPersisted(array $formConfig, array $persistedConfig): array
    {
        foreach ($this->sensitiveFields() as $field) {
            if (empty($formConfig[$field]) && ! empty($persistedConfig[$field])) {
                $formConfig[$field] = $persistedConfig[$field];
            }
        }

        return $formConfig;
    }

    /**
     * Encrypt sensitive fields, optionally preserving existing encrypted values.
     *
     * @param  array<string, mixed>  $config  Config with plaintext values
     * @param  array<string, mixed>  $persistedEncrypted  Previously stored config with encrypted values
     * @return array<string, mixed>
     */
    public function encryptSensitiveFields(array $config, array $persistedEncrypted = []): array
    {
        foreach ($this->sensitiveFields() as $field) {
            if (! empty($config[$field])) {
                $config[$field] = \Illuminate\Support\Facades\Crypt::encryptString($config[$field]);
            } elseif (! empty($persistedEncrypted[$field])) {
                $config[$field] = $persistedEncrypted[$field];
            }
        }

        return $config;
    }

    /**
     * Make validation rules optional for sensitive fields during update.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function makeRulesOptionalForSensitiveFields(array $rules): array
    {
        $configPrefix = $this->configPropertyName();

        foreach ($this->sensitiveFields() as $field) {
            $ruleKey = "{$configPrefix}.{$field}";
            if (isset($rules[$ruleKey])) {
                $rules[$ruleKey] = ['nullable', 'string', 'max:1000'];
            }
        }

        return $rules;
    }

    /**
     * Get a summary of the configuration for display in lists/tables.
     * Returns an array of label => value pairs (sensitive fields excluded).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public function configSummary(array $config): array
    {
        return match ($this) {
            self::LOCAL => [
                'Path' => $config['path'] ?? '',
            ],
            self::S3 => array_filter([
                'Bucket' => $config['bucket'] ?? '',
                'Prefix' => $config['prefix'] ?? null,
            ]),
            self::SFTP => [
                'Host' => $this->formatHostPort($config, 22),
                'User' => $config['username'] ?? '',
                'Root' => $config['root'] ?? '/',
            ],
            self::FTP => array_filter([
                'Host' => $this->formatHostPort($config, 21),
                'User' => $config['username'] ?? '',
                'Root' => $config['root'] ?? '/',
                'SSL' => ! empty($config['ssl']) ? 'Yes' : null,
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function formatHostPort(array $config, int $defaultPort): string
    {
        $host = $config['host'] ?? '';
        $port = $config['port'] ?? $defaultPort;

        return $port === $defaultPort ? $host : "{$host}:{$port}";
    }
}
