<?php

namespace App\Livewire\Configuration;

use App\Actions\Role\CreateRoleAction;
use App\Actions\Role\DeleteRoleAction;
use App\Actions\Role\UpdateRoleAction;
use App\Enums\Ability;
use App\Exceptions\Role\RoleInUseException;
use App\Livewire\Concerns\ConfirmsDeletion;
use App\Models\User;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Silber\Bouncer\Database\Role;

/**
 * Manages the global role definitions (and their abilities) under
 * Configuration → Roles. Roles are shared across organizations; only their
 * assignment to users is per-organization (handled on the Users screen).
 */
#[Title('Configuration')]
class Roles extends Component
{
    use AuthorizesRequests, ConfirmsDeletion, Toast;

    public bool $showFormModal = false;

    #[Locked]
    public ?int $editingId = null;

    public string $title = '';

    /** @var list<string> */
    public array $abilities = [];

    public function openCreate(): void
    {
        $this->authorize('create', Role::class);

        $this->reset(['editingId', 'title', 'abilities']);
        $this->resetValidation();
        $this->showFormModal = true;
    }

    public function openEdit(int $id): void
    {
        $role = Role::query()->with('abilities')->findOrFail($id);

        $this->authorize('update', $role);

        $this->editingId = $role->getKey();
        $this->title = (string) ($role->title ?: $role->name);
        $this->abilities = array_values($role->abilities->pluck('name')->map(fn ($name) => (string) $name)->all());
        $this->resetValidation();
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $role = $this->editingId !== null ? Role::query()->findOrFail($this->editingId) : null;

        $this->authorize($role !== null ? 'update' : 'create', $role ?? Role::class);

        $this->validate([
            'title' => 'required|string|max:100',
            'abilities' => 'array',
            'abilities.*' => 'in:'.implode(',', Ability::names()),
        ]);

        $abilities = array_values(array_intersect($this->abilities, Ability::names()));

        if ($role !== null) {
            UpdateRoleAction::run($role, $this->title, $abilities);
            $this->success(__('Role updated.'));
        } else {
            CreateRoleAction::run($this->uniqueRoleName($this->title), $this->title, $abilities);
            $this->success(__('Role created.'));
        }

        $this->showFormModal = false;
        $this->reset(['editingId', 'title', 'abilities']);
    }

    public function confirmDelete(int $id): void
    {
        // The delete policy already forbids built-in roles and non-super-admins.
        $this->confirmDeletion(Role::query(), $id);
    }

    public function delete(): void
    {
        $role = $this->pendingDeletion(Role::query());

        if ($role === null) {
            return;
        }

        try {
            DeleteRoleAction::run($role);
        } catch (RoleInUseException) {
            $this->closeDeletion();
            $this->error(__('This role is still assigned to users. Give them another role before deleting it.'));

            return;
        }

        $this->closeDeletion();
        $this->success(__('Role deleted.'));
    }

    private function uniqueRoleName(string $title): string
    {
        $base = Str::slug($title) ?: 'role';
        $name = $base;
        $suffix = 2;

        while (Role::query()->where('name', $name)->exists()) {
            $name = $base.'-'.$suffix;
            $suffix++;
        }

        return $name;
    }

    public function render(): View
    {
        $roles = Role::query()
            ->with('abilities')
            ->orderBy('id')
            ->get();

        return view('livewire.configuration.roles', [
            'roles' => $roles,
            'memberCounts' => $this->memberCounts(),
            'abilityGroups' => Ability::grouped(),
            'headers' => [
                ['key' => 'name', 'label' => __('Role')],
                ['key' => 'abilities', 'label' => __('Abilities')],
                ['key' => 'members', 'label' => __('Members'), 'class' => 'w-28 whitespace-nowrap'],
                ['key' => 'actions', 'label' => '', 'class' => 'w-24 whitespace-nowrap'],
            ],
        ]);
    }

    /**
     * Distinct users holding each role, counted across all organizations (roles
     * are global, so this is the application-wide membership).
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function memberCounts(): \Illuminate\Support\Collection
    {
        return DB::table('assigned_roles')
            ->where('entity_type', (new User)->getMorphClass())
            ->select('role_id', DB::raw('count(distinct entity_id) as aggregate'))
            ->groupBy('role_id')
            ->pluck('aggregate', 'role_id');
    }
}
