<?php

namespace App\Enums\Concerns;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Handling of the config fields a type stores encrypted (passwords, keys,
 * tokens), for types whose config is saved as a JSON column.
 */
trait HasSensitiveConfigFields
{
    /**
     * Fields that should be encrypted when storing in the database.
     *
     * @return string[]
     */
    abstract public function sensitiveFields(): array;

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
     * Remove sensitive fields (for storing in metadata/logs or API output).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function stripSensitiveFields(array $config): array
    {
        foreach ($this->sensitiveFields() as $field) {
            unset($config[$field]);
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
                $config[$field] = Crypt::encryptString($config[$field]);
            } elseif (! empty($persistedEncrypted[$field])) {
                $config[$field] = $persistedEncrypted[$field];
            }
        }

        return $config;
    }

    /**
     * Decrypt sensitive fields of a stored config.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function decryptSensitiveFields(array $config): array
    {
        foreach ($this->sensitiveFields() as $field) {
            if (! empty($config[$field])) {
                try {
                    $config[$field] = Crypt::decryptString($config[$field]);
                } catch (DecryptException) {
                    // Value is not encrypted (legacy data), return as-is
                }
            }
        }

        return $config;
    }
}
