<?php

namespace App\Http\Requests\Api\V1;

use App\Models\DatabaseServer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveScheduledRestoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'source_server_id' => ['required', 'string', 'exists:database_servers,id'],
            'source_database_name' => ['required', 'string', 'max:255'],
            'target_server_id' => ['required', 'string', 'exists:database_servers,id'],
            'schema_name' => ['required', 'string', 'max:255'],
            'backup_schedule_id' => ['required', 'string', 'exists:backup_schedules,id'],
            'options' => ['nullable', 'array'],
            'options.force_database' => ['nullable', 'boolean'],
            'options.owner_user' => ['nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateServers($v));
    }

    /**
     * `exists` ignores Eloquent scopes, so both ids are re-resolved through the
     * org-scoped model: an unresolvable id belongs to another organization.
     */
    private function validateServers(Validator $validator): void
    {
        $sourceId = $this->input('source_server_id');
        $targetId = $this->input('target_server_id');

        if (! is_string($sourceId) || ! is_string($targetId)) {
            return;
        }

        $source = DatabaseServer::find($sourceId);
        $target = DatabaseServer::find($targetId);

        if (! $source) {
            $validator->errors()->add('source_server_id', __('The selected source server is invalid.'));
        }

        if (! $target) {
            $validator->errors()->add('target_server_id', __('The selected target server is invalid.'));
        }

        if (! $source || ! $target) {
            return;
        }

        if ($source->database_type !== $target->database_type) {
            $validator->errors()->add(
                'target_server_id',
                __('Target server type must match the source server type.')
            );
        }
    }
}
