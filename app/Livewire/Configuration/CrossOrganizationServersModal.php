<?php

namespace App\Livewire\Configuration;

use App\Enums\BackupJobStatus;
use App\Models\DatabaseServer;
use App\Models\Organization;
use App\Models\Scopes\DatabaseServerOrganizationScope;
use App\Models\Scopes\OrganizationScope;
use App\Models\Snapshot;
use App\Services\CurrentOrganization;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only, super-admin-only list of every database server across all
 * organizations with its latest backup. Opened from the Organizations
 * configuration screen.
 */
class CrossOrganizationServersModal extends Component
{
    use AuthorizesRequests, WithPagination;

    public bool $showModal = false;

    public string $search = '';

    #[On('open-cross-organization-servers-modal')]
    public function openModal(): void
    {
        $this->authorize('viewAcrossOrganizations', DatabaseServer::class);

        $this->reset('search');
        $this->resetPage('servers');
        $this->showModal = true;
    }

    public function updatedSearch(): void
    {
        $this->resetPage('servers');
    }

    /**
     * The server page is org-scoped, so the admin's current organization is
     * switched to the server's one before redirecting.
     */
    public function openServer(string $serverId): mixed
    {
        $this->authorize('viewAcrossOrganizations', DatabaseServer::class);

        $server = DatabaseServer::withoutGlobalScope(OrganizationScope::class)
            ->with('organization')
            ->findOrFail($serverId);

        app(CurrentOrganization::class)->switchTo($server->organization);

        return $this->redirect(route('database-servers.show', $server), navigate: true);
    }

    /**
     * @return LengthAwarePaginator<int, DatabaseServer>
     */
    #[Computed]
    public function servers(): LengthAwarePaginator
    {
        $latestSnapshot = Snapshot::query()
            ->withoutGlobalScope(DatabaseServerOrganizationScope::class)
            ->whereColumn('snapshots.database_server_id', 'database_servers.id')
            ->orderByDesc('snapshots.created_at')
            ->limit(1);

        /** @var LengthAwarePaginator<int, DatabaseServer> */
        return DatabaseServer::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->with('organization')
            ->addSelect([
                'latest_backup_at' => (clone $latestSnapshot)->select('snapshots.created_at'),
                'latest_backup_status' => (clone $latestSnapshot)
                    ->join('backup_jobs', 'backup_jobs.id', '=', 'snapshots.backup_job_id')
                    ->select('backup_jobs.status'),
            ])
            ->withCasts([
                'latest_backup_at' => 'datetime',
                'latest_backup_status' => BackupJobStatus::class,
            ])
            ->when($this->search, fn (Builder $query) => $query->where(function (Builder $query) {
                $query->where('database_servers.name', 'like', "%{$this->search}%")
                    ->orWhere('database_servers.host', 'like', "%{$this->search}%")
                    ->orWhereRelation('organization', 'name', 'like', "%{$this->search}%");
            }))
            ->orderBy(Organization::select('name')->whereColumn('organizations.id', 'database_servers.organization_id'))
            ->orderBy('database_servers.name')
            ->paginate(10, pageName: 'servers');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function headers(): array
    {
        return [
            ['key' => 'name', 'label' => __('Name')],
            ['key' => 'organization', 'label' => __('Organization')],
            ['key' => 'latest_backup', 'label' => __('Last backup')],
            ['key' => 'actions', 'label' => null, 'class' => 'w-12'],
        ];
    }

    public function render(): View
    {
        return view('livewire.configuration.cross-organization-servers-modal', [
            'headers' => $this->headers(),
        ]);
    }
}
