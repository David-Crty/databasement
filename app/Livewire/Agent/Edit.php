<?php

namespace App\Livewire\Agent;

use App\Livewire\Concerns\HandlesDemoMode;
use App\Livewire\Forms\AgentForm;
use App\Models\Agent;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Edit Agent')]
class Edit extends Component
{
    use AuthorizesRequests;
    use HandlesDemoMode;

    public AgentForm $form;

    public bool $showTokenModal = false;

    #[Locked]
    public ?string $newToken = null;

    public function mount(Agent $agent): void
    {
        $this->authorize('update', $agent);

        $this->form->setAgent($agent);
    }

    public function save(): void
    {
        if ($this->abortIfDemoMode('agents.index')) {
            return;
        }

        $this->authorize('update', $this->form->agent);

        $this->form->update();

        session()->flash('status', 'Agent updated successfully!');

        $this->redirect(route('agents.index'), navigate: true);
    }

    public function regenerateToken(): void
    {
        if ($this->abortIfDemoMode('agents.index')) {
            return;
        }

        $this->authorize('update', $this->form->agent);

        // Revoke all existing tokens
        $this->form->agent->tokens()->delete();

        // Create new token
        $token = $this->form->agent->createToken('agent');
        $this->newToken = $token->plainTextToken;
        $this->showTokenModal = true;
    }

    public function closeTokenModal(): void
    {
        $this->newToken = null;
        $this->showTokenModal = false;
    }

    public function render(): View
    {
        return view('livewire.agent.edit');
    }
}
