<?php

namespace App\Livewire\Configuration;

use App\Models\DatabaseServer;
use App\Models\Organization;
use App\Models\Scopes\DatabaseServerOrganizationScope;
use App\Models\Scopes\OrganizationScope;
use App\Models\Snapshot;
use App\Services\CurrentOrganization;
use App\Support\Formatters;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Overview')]
class Overview extends Component
{
    use AuthorizesRequests, WithPagination;

    #[Url]
    public string $search = '';

    /** @var array<string, string> */
    public array $sortBy = ['column' => 'name', 'direction' => 'asc'];

    public function mount(): void
    {
        $this->authorize('viewAnyGlobal', DatabaseServer::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @param  string|array<string, mixed>  $property
     */
    public function updated(string|array $property): void
    {
        if (! is_array($property) && $property != '') {
            $this->resetPage();
        }
    }

    public function clear(): void
    {
        $this->reset('search');
        $this->resetPage();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function headers(): array
    {
        return [
            ['key' => 'name', 'label' => __('Name')],
            ['key' => 'organization', 'label' => __('Organization'), 'sortable' => false],
            ['key' => 'status', 'label' => __('Last Backup'), 'sortable' => false],
            ['key' => 'actions', 'label' => null, 'sortable' => false, 'class' => 'w-12'],
        ];
    }

    /**
     * Switch the admin's current organization context to the server's org
     * before redirecting, since the show page's route-model-binding is
     * subject to OrganizationScope.
     */
    public function viewServer(string $serverId): mixed
    {
        $server = $this->switchToServerOrg($serverId);

        return $this->redirect(route('database-servers.show', $server), navigate: true);
    }

    /**
     * Same org-switch as {@see viewServer()}, but jumps straight to the
     * server's backup jobs/snapshots instead of its detail page.
     */
    public function viewJobs(string $serverId): mixed
    {
        $server = $this->switchToServerOrg($serverId);

        return $this->redirect(route('snapshots.index', ['serverFilter' => $server->id]), navigate: true);
    }

    private function switchToServerOrg(string $serverId): DatabaseServer
    {
        $this->authorize('viewAnyGlobal', DatabaseServer::class);

        $server = DatabaseServer::withoutGlobalScope(OrganizationScope::class)
            ->with('organization')
            ->findOrFail($serverId);

        app(CurrentOrganization::class)->switchTo($server->organization);

        return $server;
    }

    public function render(): View
    {
        /** @var LengthAwarePaginator<int, DatabaseServer> $servers */
        $servers = DatabaseServer::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->with('organization')
            ->addSelect([
                'latest_backup_at' => Snapshot::query()
                    ->withoutGlobalScope(DatabaseServerOrganizationScope::class)
                    ->whereColumn('database_server_id', 'database_servers.id')
                    ->orderByDesc('created_at')
                    ->select('created_at')
                    ->limit(1),
                'latest_backup_status' => Snapshot::query()
                    ->withoutGlobalScope(DatabaseServerOrganizationScope::class)
                    ->join('backup_jobs', 'backup_jobs.id', '=', 'snapshots.backup_job_id')
                    ->whereColumn('snapshots.database_server_id', 'database_servers.id')
                    ->orderByDesc('snapshots.created_at')
                    ->select('backup_jobs.status')
                    ->limit(1),
            ])
            ->when($this->search, function (Builder $query) {
                $query->where(function (Builder $q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('host', 'like', "%{$this->search}%")
                        ->orWhereHas('organization', function (Builder $oq) {
                            /** @var Builder<Organization> $oq */
                            $oq->where('name', 'like', "%{$this->search}%");
                        });
                });
            })
            ->orderBy($this->sortBy['column'], Formatters::sortDirection($this->sortBy['direction']))
            ->paginate(15);

        return view('livewire.configuration.overview', [
            'servers' => $servers,
            'headers' => $this->headers(),
        ]);
    }
}
