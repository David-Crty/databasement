<?php

namespace App\Enums;

use App\Enums\Concerns\HasSensitiveConfigFields;

enum VolumeType: string
{
    use HasSensitiveConfigFields;

    case LOCAL = 'local';
    case S3 = 's3';
    case SFTP = 'sftp';
    case FTP = 'ftp';
    case AZURE = 'azure';
    case SMB = 'smb';

    public function label(): string
    {
        return match ($this) {
            self::LOCAL => 'Local Storage',
            self::S3 => 'Amazon S3',
            self::SFTP => 'SFTP / SSH',
            self::FTP => 'FTP',
            self::AZURE => 'Azure Blob Storage',
            self::SMB => 'Samba / SMB',
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
            self::AZURE => 'o-server-stack',
            self::SMB => 'o-server',
        };
    }

    /**
     * Get the Livewire form property name for this volume type's config.
     */
    public function configPropertyName(): string
    {
        return $this->value.'Config';
    }

    /**
     * Get the config connector class for this volume type.
     *
     * @return class-string<\App\Livewire\Volume\Connectors\BaseConfig>
     */
    public function configClass(): string
    {
        return '\\App\\Livewire\\Volume\\Connectors\\'.ucfirst($this->value).'Config';
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
            self::LOCAL => [],
            self::S3 => ['secret_access_key'],
            self::SFTP, self::FTP, self::SMB => ['password'],
            self::AZURE => ['account_key'],
        };
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
                'Region' => $config['region'] ?? null,
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
            self::AZURE => array_filter([
                'Account' => $config['account_name'] ?? '',
                'Container' => $config['container'] ?? '',
                'Prefix' => $config['prefix'] ?? null,
            ]),
            self::SMB => array_filter([
                'Host' => $config['host'] ?? '',
                'Share' => $config['share'] ?? '',
                'User' => $config['username'] ?? '',
                'Domain' => $config['domain'] ?? null,
                'Root' => $config['root'] ?? '/',
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
