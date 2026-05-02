<?php

namespace App\Livewire\User;

use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\UserServerAccess;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ServerAccess extends Component
{
    use AuthorizesRequests, Toast;

    #[Locked]
    public User $user;

    public bool $showGrantModal = false;

    public string $selectedServerId = '';

    /** @var array<int, string> */
    public array $allowedDatabases = [];

    public string $databaseSearch = '';

    public bool $canDownload = true;

    public bool $canBackup = false;

    public bool $canRestore = false;

    public function mount(User $user): void
    {
        $this->user = $user;
    }

    public function openGrantModal(?string $accessId = null): void
    {
        $this->authorize('update', $this->user);

        $this->reset(['selectedServerId', 'allowedDatabases', 'databaseSearch', 'canDownload', 'canBackup', 'canRestore']);
        $this->canDownload = true;

        if ($accessId !== null) {
            $access = UserServerAccess::where('id', $accessId)
                ->where('user_id', $this->user->id)
                ->firstOrFail();

            $this->selectedServerId = $access->database_server_id;
            $this->allowedDatabases = $access->allowed_databases ?? [];
            $this->canDownload = $access->can_download;
            $this->canBackup = $access->can_backup;
            $this->canRestore = $access->can_restore;
        }

        $this->showGrantModal = true;
    }

    public function updatedSelectedServerId(): void
    {
        $this->databaseSearch = '';
    }

    /**
     * Known database names from past snapshots for the selected server.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function knownDatabases(): array
    {
        if ($this->selectedServerId === '') {
            return [];
        }

        return Snapshot::where('database_server_id', $this->selectedServerId)
            ->whereNotNull('database_name')
            ->when($this->databaseSearch !== '', function ($q) {
                $q->where('database_name', 'like', '%'.$this->databaseSearch.'%');
            })
            ->distinct()
            ->orderBy('database_name')
            ->pluck('database_name')
            ->filter(fn ($db) => ! in_array($db, $this->allowedDatabases, strict: true))
            ->values()
            ->all();
    }

    public function addDatabase(string $database): void
    {
        $db = trim($database);

        if ($db !== '' && ! in_array($db, $this->allowedDatabases, strict: true)) {
            $this->allowedDatabases[] = $db;
        }

        $this->databaseSearch = '';
    }

    public function addSearchedDatabase(): void
    {
        $this->addDatabase($this->databaseSearch);
    }

    public function removeDatabase(string $database): void
    {
        $this->allowedDatabases = array_values(
            array_filter($this->allowedDatabases, fn ($d) => $d !== $database)
        );
    }

    public function grantAccess(): void
    {
        $this->authorize('update', $this->user);

        $this->validate([
            'selectedServerId' => 'required|exists:database_servers,id',
            'canDownload' => 'boolean',
            'canBackup' => 'boolean',
            'canRestore' => 'boolean',
        ]);

        UserServerAccess::updateOrCreate(
            [
                'user_id' => $this->user->id,
                'database_server_id' => $this->selectedServerId,
            ],
            [
                'allowed_databases' => ! empty($this->allowedDatabases) ? array_values($this->allowedDatabases) : null,
                'can_download' => $this->canDownload,
                'can_backup' => $this->canBackup,
                'can_restore' => $this->canRestore,
            ]
        );

        $this->showGrantModal = false;
        $this->success(__('Access granted successfully.'));
    }

    public function revokeAccess(int $accessId): void
    {
        $this->authorize('update', $this->user);

        UserServerAccess::where('id', $accessId)
            ->where('user_id', $this->user->id)
            ->delete();

        $this->success(__('Access revoked successfully.'));
    }

    /**
     * Servers available for selection: excludes already-granted servers
     * except the one currently selected (so editing a grant keeps it visible).
     *
     * @return array<int, array<string, string>>
     */
    public function availableServerOptions(): array
    {
        $grantedIds = $this->user->serverAccesses()->pluck('database_server_id')->all();

        // When editing an existing grant, keep its server selectable
        if ($this->selectedServerId !== '') {
            $grantedIds = array_filter($grantedIds, fn ($id) => $id !== $this->selectedServerId);
        }

        return DatabaseServer::query()
            ->whereNotIn('id', array_values($grantedIds))
            ->orderBy('name')
            ->get()
            ->map(fn (DatabaseServer $server) => [
                'id' => $server->id,
                'name' => $server->name.' ('.$server->database_type->value.')',
            ])
            ->toArray();
    }

    public function render(): View
    {
        $accesses = $this->user->serverAccesses()
            ->with('databaseServer')
            ->get();

        return view('livewire.user.server-access', [
            'accesses' => $accesses,
            'availableServers' => $this->availableServerOptions(),
        ]);
    }
}
