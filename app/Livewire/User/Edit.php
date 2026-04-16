<?php

namespace App\Livewire\User;

use App\Livewire\Forms\UserForm;
use App\Models\User;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Edit User')]
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
            $this->error(__('Cannot change role. At least one administrator is required.'));

            return;
        }

        $this->success(
            title: __('User updated successfully!'),
            redirectTo: route('users.index')
        );
    }

    public function render(): View
    {
        return view('livewire.user.edit', [
            'roleOptions' => $this->form->roleOptions(),
        ]);
    }
}
