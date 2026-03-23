<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DatabaseSelectionMode;
use App\Enums\DatabaseType;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Rules\SafePath;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveDatabaseServerRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'database_type' => ['required', 'string', Rule::in(array_map(fn (DatabaseType $t) => $t->value, DatabaseType::cases()))],
            'description' => 'nullable|string|max:1000',
            'backups_enabled' => 'boolean',
            'ssh_config_id' => 'nullable|exists:database_server_ssh_configs,id',
            'agent_id' => 'nullable|exists:agents,id',
            'managed_by' => 'nullable|string|max:255',
        ];

        $type = $this->input('database_type');

        if (in_array($type, ['mysql', 'postgres', 'mongodb', 'redis'])) {
            $rules['host'] = 'required|string|max:255';
            $rules['port'] = 'required|integer|min:1|max:65535';
        }

        if (in_array($type, ['mysql', 'postgres'])) {
            $rules['username'] = 'required|string|max:255';
            $rules['password'] = 'nullable';
            $rules['dump_flags'] = ['nullable', 'string', 'max:500', 'regex:/^[a-zA-Z0-9\s\-\_\=\.\/\,\:\*\?\%\+\@]+$/'];
        }

        if (in_array($type, ['mongodb', 'redis'])) {
            $rules['username'] = 'nullable|string|max:255';
            $rules['password'] = 'nullable';
            $rules['dump_flags'] = ['nullable', 'string', 'max:500', 'regex:/^[a-zA-Z0-9\s\-\_\=\.\/\,\:\*\?\%\+\@]+$/'];
        }

        if ($type === 'mongodb') {
            $rules['auth_source'] = 'nullable|string|max:255';
        }

        if ($type === 'sqlite') {
            $rules['database_names'] = 'required|array|min:1';
            $rules['database_names.*'] = 'required|string|max:1000';
        }

        if (in_array($type, ['mysql', 'postgres', 'mongodb'])) {
            $rules['database_selection_mode'] = ['required', 'string', Rule::in(array_map(fn (DatabaseSelectionMode $m) => $m->value, DatabaseSelectionMode::cases()))];
            $rules['database_names'] = 'nullable|array';
            $rules['database_names.*'] = 'string|max:255';
            $rules['database_include_pattern'] = 'nullable|string|max:500';

            $backupsEnabled = $this->boolean('backups_enabled', true);
            $selectionMode = $this->input('database_selection_mode');

            if ($backupsEnabled && $selectionMode === 'selected') {
                $rules['database_names'] = 'required|array|min:1';
            }

            if ($backupsEnabled && $selectionMode === 'pattern') {
                $rules['database_include_pattern'] = 'required|string|max:500';
            }
        }

        $backupsEnabled = $this->boolean('backups_enabled', true);
        if ($backupsEnabled) {
            $rules['backup.volume_id'] = 'required|exists:volumes,id';
            $rules['backup.path'] = ['nullable', 'string', 'max:255', new SafePath];
            $rules['backup.backup_schedule_id'] = 'required|exists:backup_schedules,id';
            $rules['backup.retention_policy'] = 'required|string|in:'.implode(',', Backup::RETENTION_POLICIES);

            $retentionPolicy = $this->input('backup.retention_policy');

            if ($retentionPolicy === Backup::RETENTION_DAYS) {
                $rules['backup.retention_days'] = 'required|integer|min:1|max:365';
            } elseif ($retentionPolicy === Backup::RETENTION_GFS) {
                $rules['backup.gfs_keep_daily'] = 'nullable|integer|min:0|max:90';
                $rules['backup.gfs_keep_weekly'] = 'nullable|integer|min:0|max:52';
                $rules['backup.gfs_keep_monthly'] = 'nullable|integer|min:0|max:24';
            }
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validatePatternMode($validator);
            $this->validateGfsPolicy($validator);
        });
    }

    private function validatePatternMode(Validator $validator): void
    {
        $pattern = $this->input('database_include_pattern');

        if ($this->input('database_selection_mode') !== 'pattern' || ! $this->filled('database_include_pattern')) {
            return;
        }

        if (! is_string($pattern) || ! DatabaseServer::isValidDatabasePattern($pattern)) {
            $validator->errors()->add('database_include_pattern', 'The pattern is not a valid regular expression.');
        }
    }

    private function validateGfsPolicy(Validator $validator): void
    {
        if ($this->boolean('backups_enabled', true)
            && $this->input('backup.retention_policy') === Backup::RETENTION_GFS
            && empty($this->input('backup.gfs_keep_daily'))
            && empty($this->input('backup.gfs_keep_weekly'))
            && empty($this->input('backup.gfs_keep_monthly'))
        ) {
            $validator->errors()->add('backup.gfs_keep_daily', 'At least one retention tier must be configured.');
        }
    }
}
