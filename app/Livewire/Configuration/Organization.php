<?php

namespace App\Livewire\Configuration;

use App\Models\Organization as OrganizationModel;
use App\Models\Scopes\OrganizationScope;
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

    public bool $deleteOrgHasResources = false;

    public function mount(): void
    {
        $this->authorize('viewAny', OrganizationModel::class);
    }

    /**
     * @return Collection<int, OrganizationModel>
     */
    #[Computed]
    public function organizations(): Collection
    {
        return OrganizationModel::withCount([
            'users',
            'databaseServers' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class),
            'volumes' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class),
            'agents' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class),
        ])
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
        $this->deleteOrgHasResources = $org->hasResources();
        $this->showDeleteModal = true;
    }

    public function deleteOrganization(): mixed
    {
        $org = OrganizationModel::findOrFail($this->deleteOrgId);

        $this->authorize('delete', $org);

        if ($org->hasResources()) {
            $this->error(__('This organization still has resources and cannot be deleted.'));

            return null;
        }

        $org->delete();

        $this->showDeleteModal = false;
        $this->deleteOrgId = null;

        $this->success(__('Organization deleted.'));

        return $this->redirect(route('configuration.organizations'), navigate: true);
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
                ['key' => 'actions', 'label' => '', 'class' => 'w-32'],
            ],
        ]);
    }
}
