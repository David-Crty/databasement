<?php

namespace App\Livewire\Configuration;

use App\Jobs\CleanupExpiredSnapshotsJob;
use App\Jobs\VerifySnapshotFileJob;
use App\Livewire\Forms\ConfigurationForm;
use App\Models\BackupSchedule;
use App\Services\Backup\TriggerBackupAction;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

#[Title('Configuration')]
class Backup extends Component
{
    use Toast;

    public ConfigurationForm $form;

    // Schedule modal state
    public bool $showScheduleModal = false;

    public ?string $editingScheduleId = null;

    public ?string $deleteScheduleId = null;

    public bool $showDeleteScheduleModal = false;

    public function mount(): void
    {
        $this->form->loadFromConfig();
    }

    #[Computed]
    public function isAdmin(): bool
    {
        return auth()->user()->isAdmin();
    }

    public function saveBackupConfig(): void
    {
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

        $this->form->saveBackup();

        if ($this->restartScheduler()) {
            $this->success(__('Backup configuration saved.'));
        }
    }

    public function runCleanup(): void
    {
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

        CleanupExpiredSnapshotsJob::dispatch();

        $this->success(__('Snapshot cleanup job dispatched.'));
    }

    public function runVerifyFiles(): void
    {
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

        VerifySnapshotFileJob::dispatch();

        $this->success(__('Snapshot file verification job dispatched.'));
    }

    // --- Backup Schedules ---

    public function openScheduleModal(?string $scheduleId = null): void
    {
        $this->editingScheduleId = $scheduleId;
        $this->form->resetScheduleFields();

        if ($scheduleId) {
            $schedule = BackupSchedule::findOrFail($scheduleId);
            $this->form->schedule_name = $schedule->name;
            $this->form->schedule_expression = $schedule->expression;
        }

        $this->showScheduleModal = true;
    }

    public function saveSchedule(): void
    {
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

        $uniqueRule = Rule::unique('backup_schedules', 'name')
            ->when($this->editingScheduleId, fn ($rule) => $rule->ignore($this->editingScheduleId));

        $rules = $this->form->scheduleRules();
        $rules['schedule_name'][] = $uniqueRule;

        $this->form->validate($rules);

        if ($this->editingScheduleId) {
            $schedule = BackupSchedule::findOrFail($this->editingScheduleId);
            $schedule->update([
                'name' => $this->form->schedule_name,
                'expression' => $this->form->schedule_expression,
            ]);
        } else {
            BackupSchedule::create([
                'name' => $this->form->schedule_name,
                'expression' => $this->form->schedule_expression,
            ]);
        }

        $this->showScheduleModal = false;
        $this->editingScheduleId = null;
        $this->form->resetScheduleFields();

        if ($this->restartScheduler()) {
            $this->success(__('Backup schedule saved.'));
        }
    }

    public function confirmDeleteSchedule(string $scheduleId): void
    {
        $this->deleteScheduleId = $scheduleId;
        $this->showDeleteScheduleModal = true;
    }

    public function deleteSchedule(): void
    {
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

        if (! $this->deleteScheduleId) {
            return;
        }

        $schedule = BackupSchedule::withCount('backups')->findOrFail($this->deleteScheduleId);

        if ($schedule->backups_count > 0) {
            $this->error(__('Cannot delete a schedule that is in use by database servers.'));
            $this->showDeleteScheduleModal = false;
            $this->deleteScheduleId = null;

            return;
        }

        $schedule->delete();
        $this->showDeleteScheduleModal = false;
        $this->deleteScheduleId = null;

        if ($this->restartScheduler()) {
            $this->success(__('Backup schedule deleted.'));
        }
    }

    public function runSchedule(string $scheduleId, TriggerBackupAction $action): void
    {
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

        $schedule = BackupSchedule::findOrFail($scheduleId);

        $backups = $schedule->backups()
            ->whereRelation('databaseServer', 'backups_enabled', true)
            ->with(['databaseServer', 'volume'])
            ->get();

        $totalSnapshots = 0;
        $errors = [];

        foreach ($backups as $backup) {
            try {
                $userId = auth()->id();
                $result = $action->execute($backup, is_int($userId) ? $userId : null);
                $totalSnapshots += count($result['snapshots']);
            } catch (\Throwable $e) {
                $errors[] = $backup->databaseServer->name.': '.$e->getMessage();
            }
        }

        if ($totalSnapshots > 0) {
            $this->success(
                trans_choice(':count backup started successfully!|:count backups started successfully!', $totalSnapshots)
            );
        }

        if (! empty($errors)) {
            $this->error(implode('; ', $errors));
        }
    }

    // --- Computed Properties ---

    /**
     * @return Collection<int, BackupSchedule>
     */
    #[Computed]
    public function backupSchedules(): Collection
    {
        return BackupSchedule::withCount([
            'backups as backups_count' => function ($query) {
                $query->whereRelation('databaseServer', 'backups_enabled', true);
            },
            'backups as total_backups_count',
        ])
            ->with(['backups' => function ($query) {
                $query->whereRelation('databaseServer', 'backups_enabled', true);
            }, 'backups.databaseServer:id,name'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function getCompressionOptions(): array
    {
        return [
            ['id' => 'gzip', 'name' => 'gzip'],
            ['id' => 'zstd', 'name' => 'zstd'],
            ['id' => 'encrypted', 'name' => 'encrypted'],
        ];
    }

    private function restartScheduler(): bool
    {
        $result = Process::timeout(10)->run('supervisorctl -c /config/supervisord.conf restart schedule-run');

        if ($result->failed()) {
            Log::warning('Failed to restart schedule-run', [
                'exit_code' => $result->exitCode(),
                'error' => $result->errorOutput(),
            ]);
            $this->warning(
                title: __('Saved, but scheduler restart failed. Schedule changes take effect after container restart.'),
                timeout: 6000
            );

            return false;
        }

        Log::info('Scheduler restarted successfully.');

        return true;
    }

    public function render(): View
    {
        return view('livewire.configuration.backup', [
            'compressionOptions' => $this->getCompressionOptions(),
            'backupSchedules' => $this->backupSchedules(),
            'showDeprecatedBackupEnv' => config('app.has_deprecated_backup_env'),
        ]);
    }
}
