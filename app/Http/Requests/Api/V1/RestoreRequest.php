<?php

namespace App\Http\Requests\Api\V1;

use App\Models\DatabaseServer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RestoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var DatabaseServer $server */
        $server = $this->route('database_server');

        return [
            'snapshot_id' => ['required', 'string', 'exists:snapshots,id'],
            'schema_name' => $server->database_type->databaseNameRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        /** @var DatabaseServer $server */
        $server = $this->route('database_server');

        return $server->database_type->databaseNameMessages('schema_name');
    }
}
