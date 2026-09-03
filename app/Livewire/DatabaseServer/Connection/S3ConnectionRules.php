<?php

namespace App\Livewire\DatabaseServer\Connection;

use App\Livewire\DatabaseServer\Form;
use App\Rules\SafePath;

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
        return array_merge(parent::rules($form), [
            's3_bucket' => ['required', 'string', 'max:255'],
            's3_region' => ['nullable', 'string', 'max:255'],
            's3_prefix' => ['nullable', 'string', 'max:255', new SafePath],
            's3_use_path_style_endpoint' => 'boolean',
        ]);
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
            's3_bucket' => $form->s3_bucket,
            's3_region' => $form->s3_region ?: null,
            's3_prefix' => $form->s3_prefix ?: null,
            's3_use_path_style_endpoint' => $form->s3_use_path_style_endpoint ? true : null,
            'ssl_enabled' => $form->ssl_enabled ? true : null,
        ], static fn ($value) => $value !== null);
    }
}
