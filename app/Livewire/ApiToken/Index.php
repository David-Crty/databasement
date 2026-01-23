<?php

namespace App\Livewire\ApiToken;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Mary\Traits\Toast;

class Index extends Component
{
    use Toast;

    public string $tokenName = '';

    public bool $showCreateModal = false;

    public bool $showTokenModal = false;

    #[Locked]
    public ?string $newToken = null;

    #[Locked]
    public ?string $deleteTokenId = null;

    public bool $showDeleteModal = false;

    public function openCreateModal(): void
    {
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
        $this->tokenName = '';
    }

    public function createToken(): void
    {
        $this->validate([
            'tokenName' => ['required', 'string', 'max:255'],
        ]);

        $token = Auth::user()->createToken($this->tokenName);

        $this->newToken = $token->plainTextToken;
        $this->tokenName = '';
        $this->showCreateModal = false;
        $this->showTokenModal = true;
    }

    public function closeTokenModal(): void
    {
        $this->newToken = null;
        $this->showTokenModal = false;
    }

    public function confirmDelete(string $id): void
    {
        $this->deleteTokenId = $id;
        $this->showDeleteModal = true;
    }

    public function closeDeleteModal(): void
    {
        $this->deleteTokenId = null;
        $this->showDeleteModal = false;
    }

    public function deleteToken(): void
    {
        $user = Auth::user();

        $token = PersonalAccessToken::where('id', $this->deleteTokenId)
            ->where('tokenable_type', $user->getMorphClass())
            ->firstOrFail();

        // Only admin or token owner can delete
        if (! $user->isAdmin() && $token->tokenable_id !== $user->id) {
            $this->error(__('You are not authorized to revoke this token.'), position: 'toast-bottom');
            $this->deleteTokenId = null;
            $this->showDeleteModal = false;

            return;
        }

        $token->delete();

        $this->deleteTokenId = null;
        $this->showDeleteModal = false;
        $this->success(__('API token revoked successfully.'), position: 'toast-bottom');
    }

    public function canDelete(PersonalAccessToken $token): bool
    {
        $user = Auth::user();

        if ($token->tokenable_type !== $user->getMorphClass()) {
            return false;
        }

        return $user->isAdmin() || $token->tokenable_id === $user->id;
    }

    public function render(): View
    {
        $user = Auth::user();

        $query = PersonalAccessToken::with('tokenable')
            ->where('tokenable_type', $user->getMorphClass())
            ->latest();

        if (! $user->isAdmin()) {
            $query->where('tokenable_id', $user->id);
        }

        return view('livewire.api-token.index', [
            'tokens' => $query->get(),
        ])->layout('components.layouts.app', ['title' => __('API Tokens')]);
    }
}
