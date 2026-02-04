<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

class AcceptInvitation extends Component
{
    public User $user;

    public string $token;

    #[Validate('required|string|min:8|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        $user = User::where('invitation_token', $token)
            ->whereNull('invitation_accepted_at')
            ->first();

        if (! $user) {
            abort(404, 'Invalid or expired invitation link.');
        }

        $this->user = $user;
    }

    public function accept(): void
    {
        $this->validate();

        $this->user->update([
            'password' => $this->password,
            'invitation_token' => null,
            'invitation_accepted_at' => now(),
        ]);

        Auth::login($this->user);

        $this->redirect(route('dashboard'), navigate: true);
    }

    #[Layout('layouts::auth')]
    public function render(): View
    {
        return view('livewire.auth.accept-invitation');
    }
}
