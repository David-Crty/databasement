<?php

namespace App\Livewire\User;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

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

    public bool $drawer = false;

    #[Locked]
    public ?int $deleteId = null;

    public bool $showDeleteModal = false;

    public bool $showCopyModal = false;

    public string $invitationUrl = '';

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
        $this->success('Filters cleared.', position: 'toast-bottom');
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
        return [
            ['id' => User::ROLE_ADMIN, 'name' => __('Admin')],
            ['id' => User::ROLE_MEMBER, 'name' => __('Member')],
            ['id' => User::ROLE_VIEWER, 'name' => __('Viewer')],
        ];
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
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        $user = User::findOrFail($this->deleteId);

        $this->authorize('delete', $user);

        $user->delete();
        $this->deleteId = null;
        $this->showDeleteModal = false;

        $this->success('User deleted successfully.', position: 'toast-bottom');
    }

    public function render(): View
    {
        $users = User::query()
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->roleFilter !== '', function ($query) {
                $query->where('role', $this->roleFilter);
            })
            ->when($this->statusFilter !== '', function ($query) {
                if ($this->statusFilter === 'active') {
                    $query->whereNotNull('invitation_accepted_at');
                } else {
                    $query->whereNull('invitation_accepted_at');
                }
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate(15);

        return view('livewire.user.index', [
            'users' => $users,
            'headers' => $this->headers(),
            'roleFilterOptions' => $this->roleFilterOptions(),
            'statusFilterOptions' => $this->statusFilterOptions(),
        ])->layout('components.layouts.app', ['title' => __('Users')]);
    }
}
