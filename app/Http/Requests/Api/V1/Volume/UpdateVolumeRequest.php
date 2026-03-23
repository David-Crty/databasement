<?php

namespace App\Http\Requests\Api\V1\Volume;

use App\Enums\VolumeType;
use App\Models\Volume;
use App\Rules\SafePath;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVolumeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Volume $volume */
        $volume = $this->route('volume');
        $type = VolumeType::from($volume->type);

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'config' => ['required', 'array'],
        ];

        $configRules = match ($type) {
            VolumeType::LOCAL => [
                'config.path' => ['required', 'string', 'max:500', new SafePath(allowAbsolute: true)],
            ],
            VolumeType::S3 => [
                'config.bucket' => ['required', 'string', 'max:255'],
                'config.prefix' => ['nullable', 'string', 'max:255', new SafePath],
                'config.region' => ['required', 'string', 'max:255'],
                'config.access_key_id' => ['required_with:config.secret_access_key', 'nullable', 'string', 'max:255'],
                'config.secret_access_key' => ['nullable', 'string', 'max:1000'],
                'config.custom_endpoint' => ['nullable', 'string', 'max:255'],
                'config.public_endpoint' => ['nullable', 'string', 'max:255'],
                'config.use_path_style_endpoint' => ['nullable', 'boolean'],
                'config.custom_role_arn' => ['nullable', 'string', 'max:255'],
                'config.role_session_name' => ['nullable', 'string', 'max:255'],
                'config.sts_endpoint' => ['nullable', 'string', 'max:255'],
            ],
            VolumeType::SFTP => [
                'config.host' => ['required', 'string', 'max:255'],
                'config.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'config.username' => ['required', 'string', 'max:255'],
                'config.password' => ['nullable', 'string', 'max:1000'],
                'config.root' => ['nullable', 'string', 'max:500'],
                'config.timeout' => ['nullable', 'integer', 'min:1', 'max:300'],
            ],
            VolumeType::FTP => [
                'config.host' => ['required', 'string', 'max:255'],
                'config.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'config.username' => ['required', 'string', 'max:255'],
                'config.password' => ['nullable', 'string', 'max:1000'],
                'config.root' => ['nullable', 'string', 'max:500'],
                'config.ssl' => ['nullable', 'boolean'],
                'config.passive' => ['nullable', 'boolean'],
                'config.timeout' => ['nullable', 'integer', 'min:1', 'max:300'],
            ],
        };

        return [...$rules, ...$configRules];
    }
}
