<?php

namespace App\Livewire\Agent;

use App\Livewire\Concerns\HasAgentToken;
use App\Livewire\Forms\AgentForm;
use App\Models\Agent;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Edit Agent')]
class Edit extends Component
{
    use AuthorizesRequests, HasAgentToken, Toast;

    public AgentForm $form;

    public bool $showRegenerateModal = false;

    public function mount(Agent $agent): void
    {
        $this->authorize('update', $agent);

        $this->form->setAgent($agent);
    }

    public function save(): void
    {
        $this->authorize('update', $this->form->agent);

        $this->form->update();

        $this->success(
            title: __('Agent updated successfully!'),
            redirectTo: route('agents.index')
        );
    }

    public function confirmRegenerate(): void
    {
        $this->showRegenerateModal = true;
    }

    public function regenerateToken(): void
    {
        $this->authorize('update', $this->form->agent);

        $this->form->agent->tokens()->delete();

        $token = $this->form->agent->createToken('agent');
        $this->showRegenerateModal = false;
        $this->showTokenModal($token->plainTextToken);
    }

    public function render(): View
    {
        return view('livewire.agent.edit');
    }
}
