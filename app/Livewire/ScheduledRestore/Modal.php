<?php

namespace App\Livewire\ScheduledRestore;

use App\Enums\DatabaseType;
use App\Livewire\Concerns\InteractsWithTargetDatabases;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\ScheduledRestore;
use App\Models\Snapshot;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class Modal extends Component
{
    use AuthorizesRequests, InteractsWithTargetDatabases, Toast;

    public bool $showModal = false;

    public int $currentStep = 1;

    #[Locked]
    public ?string $editingId = null;

    public ?string $sourceServerId = null;

    public ?string $sourceDatabaseName = null;

    public string $name = '';

    public ?string $backupScheduleId = null;

    public bool $enabled = true;

    #[On('open-scheduled-restore-modal')]
    public function open(?string $id = null): void
    {
        $this->reset([
            'currentStep', 'editingId', 'sourceServerId', 'sourceDatabaseName',
            'targetServerId', 'schemaName', 'forceDatabase', 'ownerUser',
            'name', 'backupScheduleId', 'enabled', 'existingDatabases',
        ]);
        $this->resetValidation();

        if ($id) {
            $scheduledRestore = ScheduledRestore::findOrFail($id);
            $this->authorize('update', $scheduledRestore);

            $this->editingId = $scheduledRestore->id;
            $this->sourceServerId = $scheduledRestore->source_server_id;
            $this->sourceDatabaseName = $scheduledRestore->source_database_name;
            $this->targetServerId = $scheduledRestore->target_server_id;
            $this->schemaName = $scheduledRestore->schema_name;
            $this->forceDatabase = (bool) $scheduledRestore->getOption('force_database', false);
            $this->ownerUser = (string) $scheduledRestore->getOption('owner_user', '');
            $this->name = $scheduledRestore->name;
            $this->backupScheduleId = $scheduledRestore->backup_schedule_id;
            $this->enabled = $scheduledRestore->enabled;

            $this->loadExistingDatabases(DatabaseServer::find($this->targetServerId));
        } else {
            $this->authorize('create', ScheduledRestore::class);
        }

        $this->showModal = true;
    }

    public function updatedSourceServerId(): void
    {
        $this->sourceDatabaseName = null;
    }

    public function updatedTargetServerId(): void
    {
        $this->loadExistingDatabases($this->targetServerId ? DatabaseServer::find($this->targetServerId) : null);
    }

    public function nextStep(): void
    {
        if ($this->currentStep === 1) {
            $this->validateSourceStep();
            $this->currentStep = 2;

            return;
        }

        if ($this->currentStep === 2) {
            $this->validateTargetStep();
            $this->currentStep = 3;
        }
    }

    public function previousStep(): void
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    public function save(): void
    {
        $this->validateSourceStep();
        $this->validateTargetStep();
        $this->validateScheduleStep();

        $payload = [
            'name' => $this->name,
            'source_server_id' => $this->sourceServerId,
            'source_database_name' => $this->sourceDatabaseName ?: null,
            'target_server_id' => $this->targetServerId,
            'schema_name' => $this->schemaName,
            'backup_schedule_id' => $this->backupScheduleId,
            'enabled' => $this->enabled,
            'options' => $this->buildOptions() ?: null,
        ];

        if ($this->editingId) {
            $scheduledRestore = ScheduledRestore::findOrFail($this->editingId);
            $this->authorize('update', $scheduledRestore);
            $scheduledRestore->update($payload);
        } else {
            $this->authorize('create', ScheduledRestore::class);
            ScheduledRestore::create($payload);
        }

        $this->showModal = false;

        $this->success($this->editingId
            ? __('Scheduled restore updated.')
            : __('Scheduled restore created.'));

        $this->dispatch('scheduled-restore-saved');
    }

    protected function validateSourceStep(): void
    {
        $rules = [
            'sourceServerId' => 'required|exists:database_servers,id',
        ];

        $sourceServer = $this->sourceServerId
            ? DatabaseServer::find($this->sourceServerId)
            : null;

        if ($sourceServer && $this->sourceServerRequiresDatabaseName($sourceServer)) {
            $rules['sourceDatabaseName'] = 'required|string|max:255';
        }

        $this->validate($rules, [
            'sourceServerId.required' => __('Please select a source server.'),
            'sourceDatabaseName.required' => __('Please select the source database.'),
        ]);
    }

    protected function validateTargetStep(): void
    {
        $rules = [
            'targetServerId' => 'required|exists:database_servers,id',
        ];

        $this->validate($rules, [
            'targetServerId.required' => __('Please select a target server.'),
        ]);

        $target = DatabaseServer::findOrFail($this->targetServerId);
        $isSqlite = $target->database_type === DatabaseType::SQLITE;

        if ($isSqlite) {
            $this->validate(
                ['schemaName' => 'required|string|max:255'],
                ['schemaName.required' => __('Please enter a database path.')]
            );
        } else {
            $this->validate(
                ['schemaName' => 'required|string|max:64|regex:/^[a-zA-Z0-9_]+$/'],
                [
                    'schemaName.required' => __('Please enter a database name.'),
                    'schemaName.regex' => __('Database name can only contain letters, numbers, and underscores.'),
                ]
            );
        }

        if ($target->isAppDatabase($this->schemaName)) {
            $this->addError('schemaName', __('Cannot restore over the application database.'));
            $this->validate(['schemaName' => 'prohibited']);
        }

        $source = DatabaseServer::findOrFail($this->sourceServerId);
        if ($source->database_type !== $target->database_type) {
            $this->addError('targetServerId', __('Target server type must match the source server type.'));
            $this->validate(['targetServerId' => 'prohibited']);
        }
    }

    protected function validateScheduleStep(): void
    {
        $this->validate([
            'name' => 'required|string|max:100',
            'backupScheduleId' => 'required|exists:backup_schedules,id',
            'enabled' => 'boolean',
        ], [
            'backupScheduleId.required' => __('Please select a schedule.'),
            'backupScheduleId.exists' => __('The selected schedule does not exist.'),
        ]);
    }

    protected function sourceServerRequiresDatabaseName(DatabaseServer $server): bool
    {
        return ! in_array($server->database_type, [DatabaseType::REDIS, DatabaseType::SQLITE], true);
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function getSourceServerOptionsProperty(): array
    {
        return DatabaseServer::query()
            ->whereHas('snapshots', fn ($q) => $q->whereHas('job', fn ($jq) => $jq->whereRaw('status = ?', ['completed'])))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (DatabaseServer $s) => ['id' => $s->id, 'name' => $s->name])
            ->toArray();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function getSourceDatabaseOptionsProperty(): array
    {
        if (! $this->sourceServerId) {
            return [];
        }

        return Snapshot::query()
            ->where('database_server_id', $this->sourceServerId)
            ->whereHas('job', fn ($q) => $q->whereRaw('status = ?', ['completed']))
            ->where('file_exists', true)
            ->distinct()
            ->orderBy('database_name')
            ->pluck('database_name')
            ->map(fn (string $name) => ['id' => $name, 'name' => $name])
            ->values()
            ->toArray();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function getTargetServerOptionsProperty(): array
    {
        if (! $this->sourceServerId) {
            return [];
        }

        $source = DatabaseServer::find($this->sourceServerId);
        if (! $source) {
            return [];
        }

        return DatabaseServer::query()
            ->whereRaw('database_type = ?', [$source->database_type->value])
            ->orderBy('name')
            ->get(['id', 'name', 'host', 'port'])
            ->map(fn (DatabaseServer $s) => ['id' => $s->id, 'name' => $this->serverOptionLabel($s)])
            ->toArray();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function getBackupScheduleOptionsProperty(): array
    {
        return BackupSchedule::query()
            ->orderBy('name')
            ->get(['id', 'name', 'expression'])
            ->map(fn (BackupSchedule $s) => ['id' => $s->id, 'name' => "{$s->name} ({$s->expression})"])
            ->toArray();
    }

    public function getSourceServerRequiresDatabaseProperty(): bool
    {
        if (! $this->sourceServerId) {
            return true;
        }

        $source = DatabaseServer::find($this->sourceServerId);

        return $source ? $this->sourceServerRequiresDatabaseName($source) : true;
    }

    public function getTargetServerProperty(): ?DatabaseServer
    {
        return $this->targetServerId ? DatabaseServer::find($this->targetServerId) : null;
    }

    public function render(): View
    {
        return view('livewire.scheduled-restore.modal');
    }
}
