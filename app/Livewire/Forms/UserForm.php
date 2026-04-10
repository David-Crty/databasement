<?php

namespace App\Livewire\Forms;

use App\Models\User;
use Livewire\Attributes\Validate;
use Livewire\Form;

class UserForm extends Form
{
    public ?User $user = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|email|max:255')]
    public string $email = '';

    #[Validate('required|in:viewer,member,admin')]
    public string $role = User::ROLE_MEMBER;

    public function setUser(User $user): void
    {
        $this->user = $user;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->role;
    }

    public function store(): User
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'role' => 'required|in:viewer,member,admin',
        ]);

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'password' => null,
        ]);

        $user->generateInvitationToken();

        return $user;
    }

    public function update(): bool
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$this->user->id,
            'role' => 'required|in:viewer,member,admin',
        ]);

        // Check if trying to change role from admin and this is the last admin
        if ($this->user->isAdmin() && $this->role !== User::ROLE_ADMIN) {
            $adminCount = User::where('role', User::ROLE_ADMIN)->count();
            if ($adminCount === 1) {
                return false;
            }
        }

        $this->user->update([
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
        ]);

        return true;
    }

    /**
     * @return array<int, array{id: string, name: string, description: string, icon: string}>
     */
    public function roleOptions(): array
    {
        return [
            ['id' => User::ROLE_VIEWER, 'name' => __('Viewer'), 'description' => __('Read-only access to all resources'), 'icon' => User::roleIcon(User::ROLE_VIEWER)],
            ['id' => User::ROLE_MEMBER, 'name' => __('Member'), 'description' => __('Full access except user management'), 'icon' => User::roleIcon(User::ROLE_MEMBER)],
            ['id' => User::ROLE_ADMIN, 'name' => __('Admin'), 'description' => __('Full access including user management'), 'icon' => User::roleIcon(User::ROLE_ADMIN)],
        ];
    }
}
