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

#[Title('Edit Agent')]
class Edit extends Component
{
    use AuthorizesRequests;
    use HasAgentToken;
    use Toast;

    public AgentForm $form;

    public bool $showRegenerateModal = false;

    public function mount(Agent $agent): void
    {
        $this->authorize('viewForm', $agent);

        $this->form->setAgent($agent);
    }

    public function save(): void
    {
        if (Gate::denies('update', $this->form->agent)) {
            session()->flash('demo_notice', __('Demo mode is enabled. Changes cannot be saved.'));
            $this->redirect(route('agents.index'), navigate: true);

            return;
        }

        $this->form->update();

        session()->flash('status', 'Agent updated successfully!');

        $this->redirect(route('agents.index'), navigate: true);
    }

    public function confirmRegenerate(): void
    {
        $this->showRegenerateModal = true;
    }

    public function regenerateToken(): void
    {
        if (Gate::denies('update', $this->form->agent)) {
            session()->flash('demo_notice', __('Demo mode is enabled. Changes cannot be saved.'));
            $this->redirect(route('agents.index'), navigate: true);

            return;
        }

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
