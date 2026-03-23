<?php

namespace App\Http\Requests\Api\V1\Volume;

use App\Enums\VolumeType;
use Illuminate\Foundation\Http\FormRequest;

abstract class StoreVolumeRequest extends FormRequest
{
    abstract protected function volumeType(): VolumeType;

    /**
     * @return array<string, mixed>
     */
    abstract protected function configRules(): array;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
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
