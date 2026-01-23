<?php

namespace App\Livewire\Settings;

use App\Livewire\Actions\Logout;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Component;

class DeleteUserForm extends Component
{
    #[Validate('required|string|current_password')]
    public string $password = '';

    public bool $showDeleteModal = false;

    public function deleteUser(Logout $logout): void
    {
        $this->validate();

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.settings.delete-user-form');
    }
}
