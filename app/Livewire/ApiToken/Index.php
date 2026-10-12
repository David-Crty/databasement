<?php

namespace App\Livewire\ApiToken;

use App\Enums\Ability;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
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
            'tokenAbilities.*' => Rule::in($this->grantableAbilities()),
        ]);

        $abilities = $this->fullAccess ? ['*'] : $this->tokenAbilities;

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

    /**
     * Catalogue abilities the current user holds, the only ones a scoped token may carry.
     *
     * @return list<string>
     */
    private function grantableAbilities(): array
    {
        $user = Auth::user();

        return array_values(array_filter(Ability::names(), fn (string $ability) => $user->can($ability)));
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

        $grantable = $this->grantableAbilities();

        return view('livewire.api-token.index', [
            'tokens' => $query->get(),
            'abilityGroups' => array_filter(array_map(
                fn (array $abilities) => array_values(array_filter(
                    $abilities,
                    fn (Ability $ability) => in_array($ability->value, $grantable, true),
                )),
                Ability::grouped(),
            )),
        ]);
    }
}
