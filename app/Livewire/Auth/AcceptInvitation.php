<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class AcceptInvitation extends Component
{
    use Toast;

    #[Locked]
    public User $user;

    #[Locked]
    public string $token;

    #[Validate('required|string|min:8|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->user = $this->pendingInvitation();
    }

    public function accept(): void
    {
        $this->validate();

        $user = $this->pendingInvitation();

        $user->update([
            'password' => $this->password,
            'invitation_token' => null,
            'invitation_accepted_at' => now(),
        ]);

        Auth::login($user);

        $this->success(
            title: __('Welcome! Your account is ready.'),
            redirectTo: route('dashboard')
        );
    }

    /**
     * The user the token still invites, resolved from the database on every
     * call so a pending invitation is what grants the password change, not
     * the state the page was rendered with.
     */
    private function pendingInvitation(): User
    {
        $user = User::where('invitation_token', $this->token)
            ->whereNull('invitation_accepted_at')
            ->first();

        if (! $user) {
            abort(404, __('Invalid or expired invitation link.'));
        }

        return $user;
    }

    #[Layout('layouts::auth')]
    public function render(): View
    {
        return view('livewire.auth.accept-invitation');
    }
}
