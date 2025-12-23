<?php

namespace App\Livewire\User;

use App\Livewire\Forms\UserForm;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Mary\Traits\Toast;

class Edit extends Component
{
    use AuthorizesRequests, Toast;

    public UserForm $form;

    public function mount(User $user): void
    {
        $this->authorize('update', $user);

        $this->form->setUser($user);
    }

    public function save(): void
    {
        $this->authorize('update', $this->form->user);

        if (! $this->form->update()) {
            $this->error('Cannot change role. At least one administrator is required.', position: 'toast-bottom');

            return;
        }

        session()->flash('status', 'User updated successfully!');

        $this->redirect(route('users.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.user.edit', [
            'roleOptions' => $this->form->roleOptions(),
        ])->layout('components.layouts.app', ['title' => __('Edit User')]);
    }
}
