<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DatabaseType;
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
        $isSqlite = $server->database_type === DatabaseType::SQLITE;

        $schemaRules = $isSqlite
            ? ['required', 'string', 'max:255']
            : ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_]+$/'];

        return [
            'snapshot_id' => ['required', 'string', 'exists:snapshots,id'],
            'schema_name' => $schemaRules,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'schema_name.regex' => 'Database name can only contain letters, numbers, and underscores.',
        ];
    }
}
