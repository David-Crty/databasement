<?php

namespace App\Livewire\Agent;

use App\Livewire\Concerns\HasAgentToken;
use App\Livewire\Forms\AgentForm;
use App\Models\Agent;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;
use Mary\Traits\Toast;

#[Title('Create Agent')]
class Create extends Component
{
    use AuthorizesRequests;
    use HasAgentToken;
    use Toast;

    public AgentForm $form;

    public function mount(): void
    {
        $this->authorize('viewForm', Agent::class);
    }

    public function save(): void
    {
        if (Gate::denies('create', Agent::class)) {
            session()->flash('demo_notice', __('Demo mode is enabled. Changes cannot be saved.'));
            $this->redirect(route('agents.index'), navigate: true);

            return;
        }

        $agent = $this->form->store();

        $token = $agent->createToken('agent');
        $this->showTokenModal($token->plainTextToken);
    }

    public function closeTokenModal(): void
    {
        $this->resetTokenModal();

        session()->flash('status', 'Agent created successfully!');
        $this->redirect(route('agents.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.agent.create');
    }
}
