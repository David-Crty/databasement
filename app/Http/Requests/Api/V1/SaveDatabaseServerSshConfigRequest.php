<?php

namespace App\Http\Requests\Api\V1;

use App\Models\DatabaseServerSshConfig;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveDatabaseServerSshConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'host' => 'required|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'required|string|max:255',
            'auth_type' => ['required', 'string', Rule::in(['password', 'key'])],
            'compression' => 'boolean',
            'password' => 'nullable|string',
            'private_key' => 'nullable|string',
            'key_passphrase' => 'nullable|string',
            'generate_key' => 'boolean',
        ];

        // On update the credentials are optional: omitting them keeps the ones
        // already stored (they are never readable back through the API).
        if ($this->existingConfig() !== null) {
            return $rules;
        }

        if ($this->input('auth_type') === 'password') {
            $rules['password'] = 'required|string';
        }

        if ($this->input('auth_type') === 'key' && ! $this->boolean('generate_key')) {
            $rules['private_key'] = 'required|string';
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $authType = $this->input('auth_type');

            if ($this->boolean('generate_key')) {
                if ($authType !== 'key') {
                    $validator->errors()->add('generate_key', 'Key generation is only available for key-based authentication.');
                }

                if ($this->filled('private_key')) {
                    $validator->errors()->add('private_key', 'A private key cannot be provided when requesting key generation.');
                }
            }

            $existing = $this->existingConfig();

            if ($existing === null) {
                return;
            }

            // Switching auth type on an existing config requires the matching
            // credential unless one is already stored for that type.
            if ($authType === 'password' && ! $this->filled('password') && blank($existing->password)) {
                $validator->errors()->add('password', 'The password field is required when switching to password authentication.');
            }

            if ($authType === 'key' && ! $this->filled('private_key') && ! $this->boolean('generate_key') && blank($existing->private_key)) {
                $validator->errors()->add('private_key', 'The private key field is required when switching to key authentication.');
            }
        });
    }

    private function existingConfig(): ?DatabaseServerSshConfig
    {
        /** @var DatabaseServerSshConfig|null $config */
        $config = $this->route('database_server_ssh_config');

        return $config;
    }
}
