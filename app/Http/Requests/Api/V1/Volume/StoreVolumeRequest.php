<?php

namespace App\Http\Requests\Api\V1\Volume;

use App\Enums\VolumeType;
use App\Services\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class StoreVolumeRequest extends FormRequest
{
    abstract protected function volumeType(): VolumeType;

    /**
     * @return array<string, mixed>
     */
    protected function configRules(): array
    {
        return $this->volumeType()->configClass()::requestRules('config');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('volumes', 'name')->where('organization_id', app(CurrentOrganization::class)->id())],
            'config' => ['required', 'array'],
            ...$this->configRules(),
        ];
    }

    /**
     * Include the volume type in validated data.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        if ($key === null) {
            $validated['type'] = $this->volumeType()->value;
        }

        return $validated;
    }
}
