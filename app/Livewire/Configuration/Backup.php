<?php

namespace App\Livewire\Configuration;

use App\Jobs\CleanupExpiredSnapshotsJob;
use App\Jobs\VerifySnapshotFileJob;
use App\Livewire\Forms\ConfigurationForm;
use App\Models\BackupSchedule;
use App\Services\CurrentOrganization;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
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
    public function canManage(): bool
    {
        return auth()->user()->can('manageSettings', BackupSchedule::class);
    }

    public function saveBackupConfig(): void
    {
        abort_unless(auth()->user()->can('manageSettings', BackupSchedule::class), Response::HTTP_FORBIDDEN);

        $this->form->saveBackup();

        $this->success(__('Backup configuration saved.'));
    }

    public function runCleanup(): void
    {
        abort_unless(auth()->user()->can('manageSettings', BackupSchedule::class), Response::HTTP_FORBIDDEN);

        CleanupExpiredSnapshotsJob::dispatch();

        $this->success(__('Snapshot cleanup job dispatched.'));
    }

    public function runVerifyFiles(): void
    {
        abort_unless(auth()->user()->can('manageSettings', BackupSchedule::class), Response::HTTP_FORBIDDEN);

        VerifySnapshotFileJob::dispatch(app(CurrentOrganization::class)->id());

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
        $schedule = $this->editingScheduleId ? BackupSchedule::findOrFail($this->editingScheduleId) : null;

        abort_unless(
            auth()->user()->can($schedule ? 'update' : 'create', $schedule ?? BackupSchedule::class),
            Response::HTTP_FORBIDDEN,
        );

        $uniqueRule = Rule::unique('backup_schedules', 'name')
            ->when($this->editingScheduleId, fn ($rule) => $rule->ignore($this->editingScheduleId));

        $rules = $this->form->scheduleRules();
        $rules['schedule_name'][] = $uniqueRule;

        $this->form->validate($rules);

        if ($schedule) {
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

        $this->success(__('Backup schedule saved.'));
    }

    public function confirmDeleteSchedule(string $scheduleId): void
    {
        $this->deleteScheduleId = $scheduleId;
        $this->showDeleteScheduleModal = true;
    }

    public function deleteSchedule(): void
    {
        if (! $this->deleteScheduleId) {
            return;
        }

        $schedule = BackupSchedule::withCount([
            'backups as total_backups_count',
            'scheduledRestores as scheduled_restores_count',
        ])->findOrFail($this->deleteScheduleId);

        abort_unless(auth()->user()->can('delete', $schedule), Response::HTTP_FORBIDDEN);

        if ((int) $schedule->getAttribute('total_backups_count') > 0) {
            $this->error(__('Cannot delete a schedule that is in use by database servers.'));
            $this->showDeleteScheduleModal = false;
            $this->deleteScheduleId = null;

            return;
        }

        if ((int) $schedule->getAttribute('scheduled_restores_count') > 0) {
            $this->error(__('Cannot delete a schedule that is in use by scheduled restores.'));
            $this->showDeleteScheduleModal = false;
            $this->deleteScheduleId = null;

            return;
        }

        $schedule->delete();
        $this->showDeleteScheduleModal = false;
        $this->deleteScheduleId = null;

        $this->success(__('Backup schedule deleted.'));
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
            'scheduledRestores as scheduled_restores_count',
        ])
            ->with([
                'backups' => function ($query) {
                    $query->whereRelation('databaseServer', 'backups_enabled', true);
                },
                'backups.databaseServer:id,name',
                'scheduledRestores:id,name,backup_schedule_id',
            ])
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

    public function render(): View
    {
        return view('livewire.configuration.backup', [
            'compressionOptions' => $this->getCompressionOptions(),
            'backupSchedules' => $this->backupSchedules(),
            'showDeprecatedBackupEnv' => config('app.has_deprecated_backup_env'),
            'scheduleHeaders' => [
                ['key' => 'name', 'label' => __('Name')],
                ['key' => 'expression', 'label' => __('Schedule')],
                ['key' => 'usage', 'label' => __('Used by')],
                ['key' => 'actions', 'label' => '', 'class' => 'w-24 whitespace-nowrap'],
            ],
        ]);
    }
}
