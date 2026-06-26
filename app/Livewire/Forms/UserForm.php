<?php

namespace App\Livewire\Forms;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\CurrentOrganization;
use Livewire\Attributes\Validate;
use Livewire\Form;

class UserForm extends Form
{
    public ?User $user = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|email|max:255')]
    public string $email = '';

    /** Per-org role (for the current org context) */
    #[Validate('required|in:viewer,operator,member,admin')]
    public string $role = UserRole::Member->value;

    /** Super admin flag (only super admins can set this) */
    public bool $superAdmin = false;

    public function setUser(User $user): void
    {
        $this->user = $user;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->superAdmin = $user->super_admin;

        $currentOrg = app(CurrentOrganization::class);
        $this->role = ($user->roleIn($currentOrg->model()) ?? UserRole::Member)->value;
    }

    public function store(): User
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'role' => 'required|'.UserRole::validationRule(),
        ]);

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => null,
            'super_admin' => auth()->user()->isSuperAdmin() ? $this->superAdmin : false,
        ]);

        $currentOrg = app(CurrentOrganization::class);
        $user->organizations()->attach($currentOrg->id(), ['role' => $this->role]);

        $user->generateInvitationToken();

        return $user;
    }

    public function update(): bool
    {
        $isOAuthUser = $this->user->isOAuth();

        $this->validate([
            'name' => 'required|string|max:255',
            'email' => $isOAuthUser ? '' : 'required|string|email|max:255|unique:users,email,'.$this->user->id,
            'role' => 'required|'.UserRole::validationRule(),
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
        $this->user->organizations()->updateExistingPivot($currentOrg->id(), ['role' => $this->role]);

        return true;
    }

    /**
     * @return array<int, array{id: string, name: string, description: string, icon: string}>
     */
    public function roleOptions(): array
    {
        return array_map(fn (UserRole $role) => [
            'id' => $role->value,
            'name' => $role->label(),
            'description' => match ($role) {
                UserRole::Viewer => __('Read-only access to all resources'),
                UserRole::Operator => __('Run backups, restores and downloads, but cannot edit server configuration'),
                UserRole::Member => __('Full access except user management'),
                UserRole::Admin => __('Full access including user management'),
                default => '',
            },
            'icon' => $role->icon(),
        ], UserRole::assignable());
    }
}
