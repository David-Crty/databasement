<?php

namespace App\Livewire\DatabaseServer\Connection;

use App\Livewire\DatabaseServer\Form;
use App\Rules\SafePath;
use App\Services\Backup\Databases\DatabaseProvider;

/**
 * Connection rules for S3-compatible object storage (MinIO, Backblaze B2, AWS
 * S3, …). The "database server" points at one bucket: host is the endpoint
 * authority, username/password are the access key, and the bucket/region/prefix
 * come from extra_config surface fields on the form.
 */
class S3ConnectionRules extends ClientServerConnectionRules
{
    public function rules(Form $form): array
    {
        $rules = array_merge(parent::rules($form), [
            's3_bucket' => ['required', 'string', 'max:255'],
            's3_region' => ['nullable', 'string', 'max:255'],
            's3_prefix' => ['nullable', 'string', 'max:255', new SafePath],
            's3_use_path_style_endpoint' => 'boolean',
        ]);

        // Refuse cleartext (non-SSL) S3 endpoints that are not loopback/private
        // hosts before persisting the access-key pair. This mirrors the runtime
        // guard in DatabaseProvider::objectStorageConfig() so Test Connection and
        // a saved server reject the same insecure configurations.
        $rules['host'][] = function (string $attribute, mixed $value, \Closure $fail) use ($form): void {
            if ($form->ssl_enabled) {
                return;
            }

            $host = is_string($value) ? trim($value) : '';

            if ($host !== '' && ! DatabaseProvider::hostIsPrivateOrLoopback($host)) {
                $fail(__('HTTP S3 endpoints are only allowed on loopback or private hosts (e.g. 127.0.0.1 or a local MinIO). Enable SSL or use a private endpoint.'));
            }
        };

        return $rules;
    }

    public function testConnectionRules(Form $form): array
    {
        // Same fields as full validation but the hidden secret can fall back to
        // the stored server key when editing.
        return array_merge($this->rules($form), [
            'password' => $form->server === null ? 'required|string|max:255' : 'nullable',
        ]);
    }

    public function extraConfig(Form $form): array
    {
        return array_filter([
            // Mirror the persisted, normalised values used by
            // DatabaseServer::buildExtraConfig() so "Test Connection" and a
            // saved server agree even when the operator leaves leading/trailing
            // whitespace in the bucket/region/prefix inputs.
            's3_bucket' => trim((string) $form->s3_bucket) ?: null,
            's3_region' => ($region = trim((string) $form->s3_region)) !== '' ? $region : null,
            's3_prefix' => ($prefix = trim((string) $form->s3_prefix)) !== '' ? $prefix : null,
            's3_use_path_style_endpoint' => $form->s3_use_path_style_endpoint ? true : null,
            'ssl_enabled' => $form->ssl_enabled ? true : null,
        ], static fn ($value) => $value !== null);
    }
}
