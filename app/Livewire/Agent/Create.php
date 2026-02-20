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

#[Title('Create Agent')]
class Create extends Component
{
    use AuthorizesRequests;
    use HandlesDemoMode;

    public AgentForm $form;

    public bool $showTokenModal = false;

    #[Locked]
    public ?string $newToken = null;

    public function mount(): void
    {
        $this->authorize('create', Agent::class);
    }

    public function save(): void
    {
        if ($this->abortIfDemoMode('agents.index')) {
            return;
        }

        $this->authorize('create', Agent::class);

        $agent = $this->form->store();

        $token = $agent->createToken('agent');
        $this->newToken = $token->plainTextToken;
        $this->showTokenModal = true;
    }

    public function closeTokenModal(): void
    {
        $this->newToken = null;
        $this->showTokenModal = false;

        session()->flash('status', 'Agent created successfully!');
        $this->redirect(route('agents.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.agent.create');
    }
}
