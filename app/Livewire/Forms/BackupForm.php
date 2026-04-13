<?php

namespace App\Livewire\Forms;

use App\Enums\DatabaseSelectionMode;
use App\Enums\DatabaseType;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\Volume;
use App\Rules\SafePath;
use App\Support\Formatters;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Helper class for a single backup-config entry inside the DatabaseServer
 * form's `backups` array. Owns the defaults, validation rules, persistence
 * shape and display helpers for one backup configuration — leaving
 * DatabaseServerForm to orchestrate the collection.
 */
final class BackupForm
{
    /**
     * Default state for a new backup-config card.
     *
     * @return array<string, mixed>
     */
    public static function defaults(?string $defaultScheduleId = null): array
    {
        return [
            'id' => null,
            'volume_id' => '',
            'path' => '',
            'backup_schedule_id' => $defaultScheduleId ?? '',
            'retention_policy' => Backup::RETENTION_DAYS,
            'retention_days' => 14,
            'gfs_keep_daily' => 7,
            'gfs_keep_weekly' => 4,
            'gfs_keep_monthly' => 12,
            'database_selection_mode' => DatabaseSelectionMode::All->value,
            'database_names' => [],
            'database_names_input' => '',
            'database_include_pattern' => '',
        ];
    }

    /**
     * Hydrate a form entry from an existing Backup model.
     *
     * @return array<string, mixed>
     */
    public static function fromModel(Backup $backup): array
    {
        return [
            'id' => $backup->id,
            'volume_id' => $backup->volume_id,
            'path' => $backup->path ?? '',
            'backup_schedule_id' => $backup->backup_schedule_id ?? '',
            'retention_policy' => $backup->retention_policy ?? Backup::RETENTION_DAYS,
            'retention_days' => $backup->retention_days ?? 14,
            'gfs_keep_daily' => $backup->gfs_keep_daily ?? 7,
            'gfs_keep_weekly' => $backup->gfs_keep_weekly ?? 4,
            'gfs_keep_monthly' => $backup->gfs_keep_monthly ?? 12,
            'database_selection_mode' => ($backup->database_selection_mode ?? DatabaseSelectionMode::All)->value,
            'database_names' => $backup->database_names ?? [],
            'database_names_input' => implode(', ', $backup->database_names ?? []),
            'database_include_pattern' => $backup->database_include_pattern ?? '',
        ];
    }

    /**
     * Convert one validated entry into the array shape accepted by
     * `Backup::create()` / `Backup::update()`.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function toPersistedData(array $entry): array
    {
        $retentionPolicy = $entry['retention_policy'] ?? Backup::RETENTION_DAYS;

        $data = [
            'volume_id' => $entry['volume_id'] ?? '',
            'path' => ! empty($entry['path']) ? $entry['path'] : null,
            'backup_schedule_id' => $entry['backup_schedule_id'] ?? '',
            'retention_policy' => $retentionPolicy,
            'retention_days' => null,
            'gfs_keep_daily' => null,
            'gfs_keep_weekly' => null,
            'gfs_keep_monthly' => null,
            'database_selection_mode' => $entry['database_selection_mode'] ?? DatabaseSelectionMode::All->value,
            'database_names' => $entry['database_names'] ?? null,
            'database_include_pattern' => ! empty($entry['database_include_pattern']) ? $entry['database_include_pattern'] : null,
        ];

        if ($retentionPolicy === Backup::RETENTION_DAYS) {
            $data['retention_days'] = $entry['retention_days'] ?? null;
        } elseif ($retentionPolicy === Backup::RETENTION_GFS) {
            $data['gfs_keep_daily'] = ! empty($entry['gfs_keep_daily']) ? (int) $entry['gfs_keep_daily'] : null;
            $data['gfs_keep_weekly'] = ! empty($entry['gfs_keep_weekly']) ? (int) $entry['gfs_keep_weekly'] : null;
            $data['gfs_keep_monthly'] = ! empty($entry['gfs_keep_monthly']) ? (int) $entry['gfs_keep_monthly'] : null;
        }

        return $data;
    }

    /**
     * Normalize selection-mode related fields based on the parent server's
     * database type.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function normalizeSelection(array &$entry, DatabaseType $serverType): void
    {
        if ($serverType === DatabaseType::REDIS) {
            $entry['database_selection_mode'] = DatabaseSelectionMode::All->value;
            $entry['database_names'] = null;
            $entry['database_include_pattern'] = null;

            return;
        }

        if ($serverType === DatabaseType::SQLITE) {
            // SQLite: keep `database_names` (file paths) and force Selected mode.
            $entry['database_selection_mode'] = DatabaseSelectionMode::Selected->value;
            $entry['database_include_pattern'] = null;
            // Strip empty path rows.
            $paths = $entry['database_names'] ?? [];
            $entry['database_names'] = array_values(array_filter(
                array_map('trim', is_array($paths) ? $paths : []),
            ));

            return;
        }

        $mode = $entry['database_selection_mode'] ?? null;

        if ($mode !== DatabaseSelectionMode::Selected->value) {
            $entry['database_names'] = null;
        }

        if ($mode !== DatabaseSelectionMode::Pattern->value) {
            $entry['database_include_pattern'] = null;
        }
    }

    /**
     * Normalize the free-text "db1, db2, db3" input into the backed array
     * when no multiselect dropdown is populated. Only applicable to
     * client-server types — SQLite uses a per-row path input.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<array{id: string, name: string}>  $availableDatabases
     */
    public static function normalizeDatabaseNames(
        array &$entry,
        array $availableDatabases,
        DatabaseType $serverType,
    ): void {
        // SQLite uses its own row-based UI; Redis has no selection.
        if (in_array($serverType, [DatabaseType::SQLITE, DatabaseType::REDIS], true)) {
            return;
        }

        // Only needed when no multiselect options are loaded.
        if (! empty($availableDatabases)) {
            return;
        }

        $input = $entry['database_names_input'] ?? '';

        $entry['database_names'] = $input === ''
            ? []
            : array_values(array_filter(
                array_map('trim', explode(',', (string) $input))
            ));
    }

    /**
     * Build validation rules for one backup entry, keyed with
     * `backups.{index}.{field}` so they slot directly into the parent form's
     * merged rules array.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function rulesFor(
        int $index,
        array $entry,
        DatabaseType $serverType,
        bool $isAgent,
    ): array {
        $prefix = "backups.{$index}.";

        $rules = [
            $prefix.'volume_id' => [
                'required',
                'exists:volumes,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($isAgent): void {
                    if ($isAgent
                        && Volume::whereKey($value)->where('type', \App\Enums\VolumeType::LOCAL->value)->exists()
                    ) {
                        $fail(__('Local volumes cannot be used with remote agents.'));
                    }
                },
            ],
            $prefix.'path' => ['nullable', 'string', 'max:255', new SafePath],
            $prefix.'backup_schedule_id' => 'required|exists:backup_schedules,id',
            $prefix.'retention_policy' => 'required|string|in:'.implode(',', Backup::RETENTION_POLICIES),
        ];

        $retentionPolicy = $entry['retention_policy'] ?? Backup::RETENTION_DAYS;

        if ($retentionPolicy === Backup::RETENTION_DAYS) {
            $rules[$prefix.'retention_days'] = 'required|integer|min:1|max:365';
        } elseif ($retentionPolicy === Backup::RETENTION_GFS) {
            $rules[$prefix.'gfs_keep_daily'] = 'nullable|integer|min:0|max:90';
            $rules[$prefix.'gfs_keep_weekly'] = 'nullable|integer|min:0|max:52';
            $rules[$prefix.'gfs_keep_monthly'] = 'nullable|integer|min:0|max:24';
        }

        // SQLite stores file paths in `database_names`; require at least one.
        if ($serverType === DatabaseType::SQLITE) {
            $rules[$prefix.'database_names'] = 'required|array|min:1';
            $rules[$prefix.'database_names.*'] = 'required|string|max:1000';
        }

        // Database selection only applies to client-server types (not SQLite
        // or Redis).
        if (! in_array($serverType, [DatabaseType::SQLITE, DatabaseType::REDIS], true)) {
            $rules[$prefix.'database_selection_mode'] = [
                'required',
                'string',
                Rule::in(array_map(fn (DatabaseSelectionMode $m) => $m->value, DatabaseSelectionMode::cases())),
            ];
            $rules[$prefix.'database_names'] = 'nullable|array';
            $rules[$prefix.'database_names.*'] = 'string|max:255';
            $rules[$prefix.'database_include_pattern'] = 'nullable|string|max:500';

            $mode = $entry['database_selection_mode'] ?? null;

            if ($mode === DatabaseSelectionMode::Selected->value) {
                $rules[$prefix.'database_names'] = 'required|array|min:1';
            }

            if ($mode === DatabaseSelectionMode::Pattern->value) {
                $rules[$prefix.'database_include_pattern'] = 'required|string|max:500';
            }
        }

        return $rules;
    }

    /**
     * Validate the pattern regex inside one entry. Mirrors the old
     * `DatabaseServerForm::validatePatternMode` behaviour per-card.
     *
     * @param  array<string, mixed>  $entry
     *
     * @throws ValidationException
     */
    public static function validatePatternMode(int $index, array $entry): void
    {
        if (($entry['database_selection_mode'] ?? null) !== DatabaseSelectionMode::Pattern->value) {
            return;
        }

        $pattern = $entry['database_include_pattern'] ?? '';

        if ($pattern === '' || DatabaseServer::isValidDatabasePattern($pattern)) {
            return;
        }

        throw ValidationException::withMessages([
            "form.backups.{$index}.database_include_pattern" => __('The pattern is not a valid regular expression.'),
        ]);
    }

    /**
     * Validate the GFS policy has at least one tier configured.
     *
     * @param  array<string, mixed>  $entry
     *
     * @throws ValidationException
     */
    public static function validateGfsPolicy(int $index, array $entry): void
    {
        if (($entry['retention_policy'] ?? null) !== Backup::RETENTION_GFS) {
            return;
        }

        if (empty($entry['gfs_keep_daily'])
            && empty($entry['gfs_keep_weekly'])
            && empty($entry['gfs_keep_monthly'])
        ) {
            throw ValidationException::withMessages([
                "form.backups.{$index}.gfs_keep_daily" => __('At least one retention tier must be configured.'),
            ]);
        }
    }

    /**
     * Whether an entry has enough fields populated to render the "complete"
     * summary callout.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function isComplete(array $entry, DatabaseType $serverType): bool
    {
        if (($entry['volume_id'] ?? '') === '' || ($entry['backup_schedule_id'] ?? '') === '') {
            return false;
        }

        $retentionPolicy = $entry['retention_policy'] ?? Backup::RETENTION_DAYS;

        if ($retentionPolicy === Backup::RETENTION_DAYS && empty($entry['retention_days'])) {
            return false;
        }

        if ($retentionPolicy === Backup::RETENTION_GFS
            && empty($entry['gfs_keep_daily'])
            && empty($entry['gfs_keep_weekly'])
            && empty($entry['gfs_keep_monthly'])
        ) {
            return false;
        }

        if ($serverType === DatabaseType::REDIS) {
            return true;
        }

        if ($serverType === DatabaseType::SQLITE) {
            $paths = $entry['database_names'] ?? [];
            $paths = array_filter(array_map('trim', is_array($paths) ? $paths : []));

            return $paths !== [];
        }

        return self::selectionSummary($entry, $serverType) !== null;
    }

    /**
     * Short description of what will be backed up for the summary callout.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function selectionSummary(array $entry, DatabaseType $serverType): ?string
    {
        if ($serverType === DatabaseType::REDIS) {
            return null;
        }

        if ($serverType === DatabaseType::SQLITE) {
            $paths = $entry['database_names'] ?? [];
            $count = is_array($paths) ? count(array_filter($paths)) : 0;

            if ($count === 0) {
                return null;
            }

            return trans_choice(
                '{1} :count file|[2,*] :count files',
                $count,
                ['count' => $count],
            );
        }

        $mode = $entry['database_selection_mode'] ?? DatabaseSelectionMode::All->value;

        if ($mode === DatabaseSelectionMode::All->value) {
            return __('all databases');
        }

        if ($mode === DatabaseSelectionMode::Selected->value) {
            /** @var array<int, string> $names */
            $names = $entry['database_names'] ?? [];
            $count = count($names);

            // Fallback: the user is typing comma-separated names, count them.
            if ($count === 0 && ! empty($entry['database_names_input'])) {
                $count = count(array_filter(
                    array_map('trim', explode(',', (string) $entry['database_names_input']))
                ));
            }

            if ($count === 0) {
                return null;
            }

            return trans_choice(
                '{1} :count database|[2,*] :count databases',
                $count,
                ['count' => $count],
            );
        }

        if ($mode === DatabaseSelectionMode::Pattern->value) {
            $pattern = $entry['database_include_pattern'] ?? '';

            if ($pattern === '') {
                return null;
            }

            return __('databases matching /:pattern/i', ['pattern' => $pattern]);
        }

        return null;
    }

    /**
     * Human-readable retention summary for the summary callout.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function retentionSummary(array $entry): string
    {
        $policy = $entry['retention_policy'] ?? Backup::RETENTION_DAYS;

        if ($policy === Backup::RETENTION_FOREVER) {
            return __('indefinitely');
        }

        if ($policy === Backup::RETENTION_DAYS) {
            $days = (int) ($entry['retention_days'] ?? 14);

            return trans_choice(
                '{1} the last :count day|[2,*] the last :count days',
                $days,
                ['count' => $days],
            );
        }

        $parts = [];

        if (! empty($entry['gfs_keep_daily'])) {
            $parts[] = trans_choice(
                '{1} :count daily|[2,*] :count daily',
                (int) $entry['gfs_keep_daily'],
                ['count' => (int) $entry['gfs_keep_daily']],
            );
        }

        if (! empty($entry['gfs_keep_weekly'])) {
            $parts[] = trans_choice(
                '{1} :count weekly|[2,*] :count weekly',
                (int) $entry['gfs_keep_weekly'],
                ['count' => (int) $entry['gfs_keep_weekly']],
            );
        }

        if (! empty($entry['gfs_keep_monthly'])) {
            $parts[] = trans_choice(
                '{1} :count monthly|[2,*] :count monthly',
                (int) $entry['gfs_keep_monthly'],
                ['count' => (int) $entry['gfs_keep_monthly']],
            );
        }

        if ($parts === []) {
            return __('GFS (not configured)');
        }

        return __('GFS (:tiers)', ['tiers' => implode(', ', $parts)]);
    }

    /**
     * Display name for the selected volume, or null if none selected.
     *
     * @param  array<string, mixed>  $entry
     * @param  Collection<int, \App\Models\Volume>  $volumes
     */
    public static function volumeLabel(array $entry, Collection $volumes): ?string
    {
        $id = $entry['volume_id'] ?? '';

        if ($id === '') {
            return null;
        }

        return $volumes->firstWhere('id', $id)?->name;
    }

    /**
     * Display label for the selected schedule — "<cron translation> (<name>)".
     *
     * @param  array<string, mixed>  $entry
     * @param  Collection<int, BackupSchedule>  $schedules
     */
    public static function scheduleLabel(array $entry, Collection $schedules): ?string
    {
        $id = $entry['backup_schedule_id'] ?? '';

        if ($id === '') {
            return null;
        }

        $schedule = $schedules->firstWhere('id', $id);

        if (! $schedule instanceof BackupSchedule) {
            return null;
        }

        return Formatters::cronTranslation($schedule->expression).' ('.$schedule->name.')';
    }

    /**
     * Resolve {year}/{month}/{day} placeholders in the subfolder path for a
     * live preview.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function resolvedPathPreview(array $entry): ?string
    {
        $path = trim((string) ($entry['path'] ?? ''));

        if ($path === '') {
            return null;
        }

        return Formatters::resolveDatePlaceholders($path);
    }
}
