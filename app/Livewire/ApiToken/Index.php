<?php

namespace App\Livewire\ApiToken;

use App\Enums\Ability;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('API Tokens')]
class Index extends Component
{
    use Toast;

    public string $tokenName = '';

    public bool $fullAccess = true;

    /** @var list<string> */
    public array $tokenAbilities = [];

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
        $this->reset(['tokenName', 'fullAccess', 'tokenAbilities']);
    }

    public function createToken(): void
    {
        $this->validate([
            'tokenName' => 'required|string|max:255',
            'tokenAbilities' => 'array',
            'tokenAbilities.*' => 'in:'.implode(',', Ability::names()),
        ]);

        $abilities = $this->fullAccess
            ? ['*']
            : array_values(array_intersect($this->tokenAbilities, Ability::names()));

        $token = Auth::user()->createToken($this->tokenName, $abilities);

        $this->newToken = $token->plainTextToken;
        $this->reset(['tokenName', 'fullAccess', 'tokenAbilities']);
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
        $token = PersonalAccessToken::findOrFail($this->deleteTokenId);

        if (! $this->canDelete($token)) {
            $this->error(__('You are not authorized to revoke this token.'));
            $this->deleteTokenId = null;
            $this->showDeleteModal = false;

            return;
        }

        $token->delete();

        $this->deleteTokenId = null;
        $this->showDeleteModal = false;
        $this->success(__('API token revoked successfully.'));
    }

    public function canDelete(PersonalAccessToken $token): bool
    {
        $user = Auth::user();

        if ($token->tokenable_type !== $user->getMorphClass()) {
            return false;
        }

        return $user->isSuperAdmin() || (string) $token->tokenable_id === (string) $user->id;
    }

    public function render(): View
    {
        $user = Auth::user();

        $query = PersonalAccessToken::with('tokenable')
            ->where('tokenable_type', $user->getMorphClass())
            ->latest();

        if (! $user->isSuperAdmin()) {
            $query->where('tokenable_id', $user->id);
        }

        return view('livewire.api-token.index', [
            'tokens' => $query->get(),
            'abilityGroups' => Ability::grouped(),
        ]);
    }
}
