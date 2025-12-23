<?php

namespace App\Livewire\User;

use App\Livewire\Forms\UserForm;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Mary\Traits\Toast;

class Create extends Component
{
    use AuthorizesRequests, Toast;

    public UserForm $form;

    public bool $showCopyModal = false;

    public string $invitationUrl = '';

    public function mount(): void
    {
        $this->authorize('create', User::class);
    }

    public function save(): void
    {
        $this->authorize('create', User::class);

        $user = $this->form->store();

        $this->invitationUrl = $user->getInvitationUrl();
        $this->showCopyModal = true;
    }

    public function closeAndRedirect(): void
    {
        $this->redirect(route('users.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.user.create', [
            'roleOptions' => $this->form->roleOptions(),
        ])->layout('components.layouts.app', ['title' => __('Create User')]);
    }
}
