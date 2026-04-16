<?php

namespace App\Livewire\Settings;

use App\Livewire\Actions\Logout;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Component;

class DeleteUserForm extends Component
{
    use Toast;

    #[Validate('required|string|current_password')]
    public string $password = '';

    public bool $showDeleteModal = false;

    public function deleteUser(Logout $logout): void
    {
        $this->validate();

        tap(Auth::user(), $logout(...))->delete();

        $this->success(
            title: __('Your account has been deleted.'),
            redirectTo: '/'
        );
    }

    public function render(): View
    {
        return view('livewire.settings.delete-user-form');
    }
}
