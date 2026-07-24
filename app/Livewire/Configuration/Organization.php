<?php

namespace App\Livewire\Configuration;

use App\Jobs\DeleteOrganizationJob;
use App\Jobs\MergeOrganizationJob;
use App\Models\Organization as OrganizationModel;
use App\Models\Scopes\OrganizationScope;
use App\Services\CurrentOrganization;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Configuration')]
class Organization extends Component
{
    use AuthorizesRequests;
    use Toast;

    public bool $showCreateModal = false;

    public string $newOrgName = '';

    public bool $showEditModal = false;

    public ?string $editingOrgId = null;

    public string $editOrgName = '';

    public bool $showDeleteModal = false;

    public ?string $deleteOrgId = null;

    public bool $keepFiles = false;

    public bool $showMergeModal = false;

    public ?string $mergeSourceId = null;

    public ?string $mergeDestinationId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', OrganizationModel::class);
    }

    /**
     * Super admins see every organization; other members see only the ones they
     * belong to, so the page never discloses other tenants' names or counts.
     *
     * @return Collection<int, OrganizationModel>
     */
    #[Computed]
    public function organizations(): Collection
    {
        $user = auth()->user();

        return OrganizationModel::withCount([
            'users',
            'databaseServers' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class),
            'volumes' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class),
            'agents' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class),
        ])
            ->when(
                ! $user->isSuperAdmin(),
                fn ($q) => $q->whereIn('id', $user->organizations()->pluck('organizations.id')),
            )
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function openCreateModal(): void
    {
        $this->newOrgName = '';
        $this->resetValidation();
        $this->showCreateModal = true;
    }

    public function createOrganization(): mixed
    {
        $this->authorize('create', OrganizationModel::class);

        $this->validate([
            'newOrgName' => 'required|string|max:255|unique:organizations,name',
        ]);

        OrganizationModel::create([
            'name' => $this->newOrgName,
        ]);

        $this->showCreateModal = false;
        $this->newOrgName = '';

        $this->success(__('Organization created.'));

        return $this->redirect(route('configuration.organizations'), navigate: true);
    }

    public function openEditModal(string $orgId): void
    {
        $org = OrganizationModel::findOrFail($orgId);

        $this->authorize('update', $org);

        $this->editingOrgId = $orgId;
        $this->editOrgName = $org->name;
        $this->resetValidation();
        $this->showEditModal = true;
    }

    public function updateOrganization(): mixed
    {
        $org = OrganizationModel::findOrFail($this->editingOrgId);

        $this->authorize('update', $org);

        $this->validate([
            'editOrgName' => 'required|string|max:255|unique:organizations,name,'.$org->id,
        ]);

        $org->update(['name' => $this->editOrgName]);

        $this->showEditModal = false;
        $this->editingOrgId = null;

        $this->success(__('Organization updated.'));

        return $this->redirect(route('configuration.organizations'), navigate: true);
    }

    public function confirmDelete(string $orgId): void
    {
        $org = OrganizationModel::findOrFail($orgId);

        $this->authorize('delete', $org);

        $this->deleteOrgId = $orgId;
        $this->keepFiles = false;
        $this->showDeleteModal = true;
    }

    public function deleteOrganization(): mixed
    {
        $org = OrganizationModel::findOrFail($this->deleteOrgId);

        $this->authorize('delete', $org);

        $this->ensureNotCurrentOrg($org);

        DeleteOrganizationJob::dispatch($org->id, $this->actorId(), $this->keepFiles);

        $this->showDeleteModal = false;
        $this->deleteOrgId = null;

        $this->success(__('Organization deletion queued. It will complete shortly.'));

        return $this->redirect(route('configuration.organizations'), navigate: true);
    }

    public function openMergeModal(string $orgId): void
    {
        $org = OrganizationModel::findOrFail($orgId);

        $this->authorize('delete', $org);

        $this->mergeSourceId = $orgId;
        $this->mergeDestinationId = null;
        $this->resetValidation();
        $this->showMergeModal = true;
    }

    public function mergeOrganization(): mixed
    {
        $source = OrganizationModel::findOrFail($this->mergeSourceId);

        $this->authorize('delete', $source);

        $this->validate([
            'mergeDestinationId' => [
                'required',
                'string',
                'exists:organizations,id',
                'different:mergeSourceId',
            ],
        ]);

        $this->ensureNotCurrentOrg($source);

        MergeOrganizationJob::dispatch($source->id, $this->mergeDestinationId, $this->actorId());

        $this->showMergeModal = false;
        $this->mergeSourceId = null;
        $this->mergeDestinationId = null;

        $this->success(__('Organization merge queued. It will complete shortly.'));

        return $this->redirect(route('configuration.organizations'), navigate: true);
    }

    /**
     * Destination options for the merge modal (all orgs except the source).
     *
     * @return array<int, array{id: string, name: string}>
     */
    #[Computed]
    public function mergeDestinations(): array
    {
        return OrganizationModel::query()
            ->when($this->mergeSourceId, fn ($q) => $q->whereKeyNot($this->mergeSourceId))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (OrganizationModel $org) => ['id' => $org->id, 'name' => $org->name])
            ->all();
    }

    private function actorId(): ?int
    {
        $id = auth()->id();

        return $id === null ? null : (int) $id;
    }

    /**
     * If the actor is currently scoped to the org being removed, switch their
     * context to the default org so they don't land on a stale organization.
     */
    private function ensureNotCurrentOrg(OrganizationModel $org): void
    {
        $current = app(CurrentOrganization::class);

        if ($current->isResolved() && $current->id() === $org->id) {
            $current->switchTo(OrganizationModel::default());
        }
    }

    public function render(): View
    {
        return view('livewire.configuration.organization', [
            'organizations' => $this->organizations(),
            'headers' => [
                ['key' => 'name', 'label' => __('Name')],
                ['key' => 'id', 'label' => __('ID')],
                ['key' => 'users_count', 'label' => __('Users')],
                ['key' => 'database_servers_count', 'label' => __('Servers')],
                ['key' => 'volumes_count', 'label' => __('Volumes')],
                ['key' => 'agents_count', 'label' => __('Agents')],
                ['key' => 'actions', 'label' => '', 'class' => 'w-32 whitespace-nowrap'],
            ],
        ]);
    }
}
