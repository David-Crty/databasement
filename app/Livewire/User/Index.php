<?php

namespace App\Livewire\User;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\CurrentOrganization;
use App\Support\Formatters;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Users')]
class Index extends Component
{
    use AuthorizesRequests, Toast, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $roleFilter = '';

    #[Url]
    public string $statusFilter = '';

    /** @var array<string, string> */
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    #[Locked]
    public ?int $deleteId = null;

    public bool $showDeleteModal = false;

    public string $deleteBlockReason = '';

    #[Locked]
    public ?int $removeId = null;

    public bool $showRemoveModal = false;

    public string $removeBlockReason = '';

    public bool $showCopyModal = false;

    public string $invitationUrl = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
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
        $this->reset(['search', 'roleFilter', 'statusFilter']);
        $this->resetPage();
        $this->success(__('Filters cleared.'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function headers(): array
    {
        return [
            ['key' => 'name', 'label' => __('Name'), 'class' => 'w-64'],
            ['key' => 'email', 'label' => __('Email')],
            ['key' => 'role', 'label' => __('Role'), 'class' => 'w-32'],
            ['key' => 'status', 'label' => __('Status'), 'class' => 'w-32', 'sortable' => false],
            ['key' => 'created_at', 'label' => __('Created'), 'class' => 'w-40'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function roleFilterOptions(): array
    {
        return array_map(fn (UserRole $role) => [
            'id' => $role->value,
            'name' => $role->label(),
        ], UserRole::assignable());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function statusFilterOptions(): array
    {
        return [
            ['id' => 'active', 'name' => __('Active')],
            ['id' => 'pending', 'name' => __('Pending')],
        ];
    }

    public function copyInvitationLink(int $id): void
    {
        $user = User::findOrFail($id);

        $this->authorize('copyInvitationLink', $user);

        $this->invitationUrl = $user->getInvitationUrl();
        $this->showCopyModal = true;
    }

    public function confirmDelete(int $id): void
    {
        $user = User::findOrFail($id);

        $this->authorize('delete', $user);

        $this->deleteId = $id;
        $this->deleteBlockReason = $this->getDeleteBlockReason($user);
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        $user = User::findOrFail($this->deleteId);

        $this->authorize('delete', $user);

        if ($this->getDeleteBlockReason($user) !== '') {
            return;
        }

        $user->delete();
        $this->deleteId = null;
        $this->showDeleteModal = false;

        $this->success(__('User deleted successfully.'));
    }

    public function confirmRemoveFromOrg(int $id): void
    {
        $user = User::findOrFail($id);

        $this->authorize('removeFromOrganization', $user);

        $this->removeId = $id;
        $this->removeBlockReason = $this->getRemoveBlockReason($user);
        $this->showRemoveModal = true;
    }

    public function removeFromOrg(): void
    {
        if (! $this->removeId) {
            return;
        }

        $user = User::findOrFail($this->removeId);

        $this->authorize('removeFromOrganization', $user);

        if ($this->getRemoveBlockReason($user) !== '') {
            return;
        }

        $currentOrg = app(CurrentOrganization::class);
        $user->organizations()->detach($currentOrg->id());

        $this->removeId = null;
        $this->showRemoveModal = false;

        $this->success(__('User removed from organization.'));
    }

    private function getDeleteBlockReason(User $user): string
    {
        if ($user->isSuperAdmin() && User::where('super_admin', true)->count() === 1) {
            return __('This is the only super admin account. It cannot be deleted.');
        }

        if (! auth()->user()->isSuperAdmin() && $user->organizations()->count() > 1) {
            return __('This user belongs to multiple organizations. Remove them from this organization instead.');
        }

        return '';
    }

    private function getRemoveBlockReason(User $user): string
    {
        if ($user->organizations()->count() <= 1) {
            return __('This user only belongs to this organization. Removing them would leave them without access.');
        }

        return '';
    }

    public function render(): View
    {
        $currentOrg = app(CurrentOrganization::class);

        $query = User::query();

        $query->whereRelation('organizations', 'organization_id', $currentOrg->id());

        $users = $query
            ->with('organizations')
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->roleFilter !== '', function ($query) use ($currentOrg) {
                $query->whereHas('organizations', fn ($q) => $q->whereRaw('organization_id = ? and role = ?', [$currentOrg->id(), $this->roleFilter]));
            })
            ->when($this->statusFilter !== '', function ($query) {
                if ($this->statusFilter === 'active') {
                    $query->whereNotNull('invitation_accepted_at');
                } else {
                    $query->whereNull('invitation_accepted_at');
                }
            })
            ->orderBy($this->sortBy['column'], Formatters::sortDirection((string) $this->sortBy['direction']))
            ->paginate(15);

        return view('livewire.user.index', [
            'users' => $users,
            'headers' => $this->headers(),
            'roleFilterOptions' => $this->roleFilterOptions(),
            'statusFilterOptions' => $this->statusFilterOptions(),
            'canManageOrgMembership' => auth()->user()->can('manageOrgMembership', User::class),
        ]);
    }
}
