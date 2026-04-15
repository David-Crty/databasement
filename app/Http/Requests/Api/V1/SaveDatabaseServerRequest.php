<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DatabaseSelectionMode;
use App\Enums\DatabaseType;
use App\Enums\VolumeType;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Volume;
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

        /** @var DatabaseServer|null $existing */
        $existing = $this->route('database_server');
        $backupsEnabled = $this->has('backups_enabled')
            ? $this->boolean('backups_enabled')
            : ($existing !== null ? $existing->backups_enabled : true);

        if ($backupsEnabled) {
            $rules['backups'] = 'required|array|min:1';
            $rules['backups.*.volume_id'] = 'required|exists:volumes,id';
            $rules['backups.*.path'] = ['nullable', 'string', 'max:255', new SafePath];
            $rules['backups.*.backup_schedule_id'] = 'required|exists:backup_schedules,id';
            $rules['backups.*.retention_policy'] = 'required|string|in:'.implode(',', Backup::RETENTION_POLICIES);
            $rules['backups.*.retention_days'] = 'nullable|integer|min:1|max:365';
            $rules['backups.*.gfs_keep_daily'] = 'nullable|integer|min:0|max:90';
            $rules['backups.*.gfs_keep_weekly'] = 'nullable|integer|min:0|max:52';
            $rules['backups.*.gfs_keep_monthly'] = 'nullable|integer|min:0|max:24';

            if ($type === 'sqlite') {
                $rules['backups.*.database_names'] = 'required|array|min:1';
                $rules['backups.*.database_names.*'] = 'required|string|max:1000';
            } elseif (in_array($type, ['mysql', 'postgres', 'mongodb'])) {
                $rules['backups.*.database_selection_mode'] = ['required', 'string', Rule::in(array_map(fn (DatabaseSelectionMode $m) => $m->value, DatabaseSelectionMode::cases()))];
                $rules['backups.*.database_names'] = 'nullable|array';
                $rules['backups.*.database_names.*'] = 'string|max:255';
                $rules['backups.*.database_include_pattern'] = 'nullable|string|max:500';
            }
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var DatabaseServer|null $existing */
            $existing = $this->route('database_server');
            $backupsEnabled = $this->has('backups_enabled')
                ? $this->boolean('backups_enabled')
                : ($existing !== null ? $existing->backups_enabled : true);

            if (! $backupsEnabled) {
                return;
            }

            $backups = $this->input('backups', []);

            if (! is_array($backups)) {
                return;
            }

            $isAgent = $this->has('agent_id')
                ? $this->filled('agent_id')
                : ($existing?->agent_id !== null);

            foreach ($backups as $index => $backup) {
                $this->validateBackupEntry($validator, $index, is_array($backup) ? $backup : [], $isAgent);
            }
        });
    }

    /**
     * Per-entry cross-field validation.
     *
     * @param  array<string, mixed>  $backup
     */
    private function validateBackupEntry(Validator $validator, int $index, array $backup, bool $isAgent): void
    {
        if ($isAgent) {
            $volumeId = $backup['volume_id'] ?? null;
            if ($volumeId !== null && Volume::whereKey($volumeId)->where('type', VolumeType::LOCAL->value)->exists()) {
                $validator->errors()->add("backups.{$index}.volume_id", 'Local volumes cannot be used with remote agents.');
            }
        }

        $retentionPolicy = $backup['retention_policy'] ?? null;

        if ($retentionPolicy === Backup::RETENTION_DAYS && empty($backup['retention_days'])) {
            $validator->errors()->add("backups.{$index}.retention_days", 'The retention days field is required when using days-based retention.');
        }

        if ($retentionPolicy === Backup::RETENTION_GFS
            && empty($backup['gfs_keep_daily'])
            && empty($backup['gfs_keep_weekly'])
            && empty($backup['gfs_keep_monthly'])
        ) {
            $validator->errors()->add("backups.{$index}.gfs_keep_daily", 'At least one retention tier must be configured.');
        }

        $mode = $backup['database_selection_mode'] ?? null;

        if ($mode === DatabaseSelectionMode::Selected->value && empty($backup['database_names'])) {
            $validator->errors()->add("backups.{$index}.database_names", 'At least one database must be selected.');
        }

        if ($mode === DatabaseSelectionMode::Pattern->value) {
            $pattern = $backup['database_include_pattern'] ?? '';

            if (! is_string($pattern) || $pattern === '') {
                $validator->errors()->add("backups.{$index}.database_include_pattern", 'The include pattern is required in pattern selection mode.');

                return;
            }

            if (! DatabaseServer::isValidDatabasePattern($pattern)) {
                $validator->errors()->add("backups.{$index}.database_include_pattern", 'The pattern is not a valid regular expression.');
            }
        }
    }
}
