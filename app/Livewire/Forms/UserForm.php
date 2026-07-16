<?php

namespace App\Livewire\Forms;

use App\Enums\Ability;
use App\Models\Organization;
use App\Models\User;
use App\Services\CurrentOrganization;
use App\Services\Roles\AssignRoleToUserAction;
use App\Services\Roles\SyncUserAbilitiesAction;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Form;
use Silber\Bouncer\Database\Role;

class UserForm extends Form
{
    public ?User $user = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|email|max:255')]
    public string $email = '';

    /** Per-org role (for the current org context) */
    #[Validate('required|string')]
    public string $role = 'member';

    /**
     * Extra abilities granted to the user directly (on top of their role), in the
     * current org. Only applied by admins who can manage roles.
     *
     * @var list<string>
     */
    public array $abilities = [];

    /** Super admin flag (only super admins can set this) */
    public bool $superAdmin = false;

    public function setUser(User $user): void
    {
        $this->user = $user;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->superAdmin = $user->super_admin;

        $currentOrg = app(CurrentOrganization::class);
        // Use the actual assigned role name so a custom role isn't silently
        // downgraded to Member when the form is saved.
        $this->role = $user->roleNameIn($currentOrg->model()) ?? 'member';
        $this->abilities = $user->directAbilitiesIn($currentOrg->model());
    }

    public function store(): User
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'role' => ['required', 'string', Rule::in($this->orgRoleNames())],
            'abilities' => 'array',
            'abilities.*' => Rule::in(Ability::names()),
        ]);

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => null,
            'super_admin' => auth()->user()->isSuperAdmin() ? $this->superAdmin : false,
        ]);

        $currentOrg = app(CurrentOrganization::class);
        $user->organizations()->attach($currentOrg->id());
        app(AssignRoleToUserAction::class)->execute($user, $this->role, $currentOrg->model());
        $this->syncAbilities($user, $currentOrg->model());

        $user->generateInvitationToken();

        return $user;
    }

    public function update(): bool
    {
        $isOAuthUser = $this->user->isOAuth();

        $this->validate([
            'name' => 'required|string|max:255',
            'email' => $isOAuthUser ? '' : 'required|string|email|max:255|unique:users,email,'.$this->user->id,
            'role' => ['required', 'string', Rule::in($this->orgRoleNames())],
            'abilities' => 'array',
            'abilities.*' => Rule::in(Ability::names()),
        ]);

        $data = $isOAuthUser
            ? ['name' => $this->name]
            : ['name' => $this->name, 'email' => $this->email];

        // Super admin flag — only super admins can change it
        if (auth()->user()->isSuperAdmin()) {
            // Cannot remove the last super admin
            if ($this->user->isSuperAdmin() && ! $this->superAdmin) {
                if (User::where('super_admin', true)->count() === 1) {
                    return false;
                }
            }

            $this->user->update([...$data, 'super_admin' => $this->superAdmin]);
        } else {
            $this->user->update($data);
        }

        $currentOrg = app(CurrentOrganization::class);
        app(AssignRoleToUserAction::class)->execute($this->user, $this->role, $currentOrg->model());
        $this->syncAbilities($this->user, $currentOrg->model());

        return true;
    }

    /**
     * Sync the user's direct abilities in the organization. Anyone with the
     * `manage-users` ability may set these. That holder can already reach every
     * catalogue ability by assigning the Admin role (which grants them all), so
     * direct abilities add no privilege beyond what role assignment already
     * allows — including granting abilities to themselves. Granting `super_admin`
     * stays separately gated. See docs/user-guide/permissions for the note.
     */
    private function syncAbilities(User $user, Organization $organization): void
    {
        if (! auth()->user()?->can(Ability::ManageUsers->value)) {
            return;
        }

        $abilities = array_values(array_intersect($this->abilities, Ability::names()));

        app(SyncUserAbilitiesAction::class)->execute($user, $abilities, $organization);
    }

    /**
     * Catalogue abilities grouped for the toggle grid in the form.
     *
     * @return array<string, list<Ability>>
     */
    public function abilityGroups(): array
    {
        return Ability::grouped();
    }

    /**
     * Roles assignable to a user: every role (except the hidden Demo role)
     * created under Configuration → Roles. Definitions are global; the assignment
     * itself is scoped to the current organization. Each option carries its
     * ability names so the picker can render them as badges (see the shared
     * `ability-badges` component).
     *
     * @return array<int, array{id: string, name: string, abilities: list<string>}>
     */
    public function roleOptions(): array
    {
        return $this->assignableRoles()
            ->map(function (Role $role) {
                $name = (string) $role->name;
                $title = (string) ($role->title ?? '');

                return [
                    'id' => $name,
                    'name' => $title !== '' ? $title : $name,
                    'abilities' => array_values(
                        $role->abilities
                            ->map(fn ($ability) => (string) $ability->getAttribute('name'))
                            ->all()
                    ),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The global set of role definitions a user can be assigned.
     *
     * @return \Illuminate\Support\Collection<int, Role>
     */
    private function assignableRoles(): \Illuminate\Support\Collection
    {
        return Role::query()
            ->with('abilities')
            ->orderBy('id')
            ->get();
    }

    /**
     * Role names that may be assigned through the form. Excludes Demo so a
     * crafted request can't assign the hidden Demo role.
     *
     * @return list<string>
     */
    private function orgRoleNames(): array
    {
        return array_values(
            $this->assignableRoles()
                ->map(fn (Role $role) => (string) $role->name)
                ->all()
        );
    }
}
