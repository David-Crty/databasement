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

#[Title('Create Agent')]
class Create extends Component
{
    use AuthorizesRequests, HasAgentToken, Toast;

    public AgentForm $form;

    public function mount(): void
    {
        $this->authorize('create', Agent::class);
    }

    public function save(): void
    {
        $this->authorize('create', Agent::class);

        $agent = $this->form->store();

        $token = $agent->createToken('agent');
        $this->showTokenModal($token->plainTextToken);
    }

    public function closeTokenModal(): void
    {
        $this->resetTokenModal();

        $this->success(
            title: __('Agent created successfully!'),
            redirectTo: route('agents.index')
        );
    }

    public function render(): View
    {
        return view('livewire.agent.create');
    }
}
