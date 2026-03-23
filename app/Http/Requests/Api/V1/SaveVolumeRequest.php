<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\VolumeType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveVolumeRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:' . implode(',', array_column(VolumeType::cases(), 'value'))],
            'config' => ['required', 'array'],
        ];

        $type = VolumeType::tryFrom($this->input('type', ''));
        if ($type !== null) {
            // Connector rules use prefixed keys like "localConfig.path" — remap to "config.path"
            foreach ($type->configRules() as $key => $fieldRules) {
                $suffix = str_replace($type->configPropertyName() . '.', '', $key);
                $remappedRules = array_map(function ($rule) use ($type) {
                    // Remap required_if/required_with references from configPropertyName to "config"
                    if (is_string($rule)) {
                        return str_replace($type->configPropertyName() . '.', 'config.', $rule);
                    }

                    return $rule;
                }, (array) $fieldRules);

                // Remap required_if:type,X to just required when the type matches
                $remappedRules = array_map(function ($rule) use ($type) {
                    if (is_string($rule) && $rule === "required_if:type,{$type->value}") {
                        return 'required';
                    }

                    return $rule;
                }, $remappedRules);

                $rules["config.{$suffix}"] = $remappedRules;
            }

            // On update, make sensitive fields optional (blank = keep existing)
            if ($this->route('volume') !== null) {
                $rules = $type->makeRulesOptionalForSensitiveFields(
                    $this->remapRulesForSensitiveFields($rules, $type)
                );
            }
        }

        return $rules;
    }

    /**
     * VolumeType::makeRulesOptionalForSensitiveFields expects keys like "localConfig.field".
     * We need to temporarily remap, apply, then remap back.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function remapRulesForSensitiveFields(array $rules, VolumeType $type): array
    {
        $configPrefix = $type->configPropertyName();
        $remapped = [];

        foreach ($rules as $key => $value) {
            if (str_starts_with($key, 'config.')) {
                $suffix = substr($key, 7); // Remove "config."
                $remapped["{$configPrefix}.{$suffix}"] = $value;
            } else {
                $remapped[$key] = $value;
            }
        }

        $remapped = $type->makeRulesOptionalForSensitiveFields($remapped);

        $result = [];
        foreach ($remapped as $key => $value) {
            if (str_starts_with($key, "{$configPrefix}.")) {
                $suffix = substr($key, strlen($configPrefix) + 1);
                $result["config.{$suffix}"] = $value;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
