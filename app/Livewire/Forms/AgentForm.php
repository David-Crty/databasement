<?php

namespace App\Livewire\Forms;

use App\Models\Agent;
use Livewire\Form;

class AgentForm extends Form
{
    public ?Agent $agent = null;

    public string $name = '';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
        ];
    }

    public function setAgent(Agent $agent): void
    {
        $this->agent = $agent;
        $this->name = $agent->name;
    }

    public function store(): Agent
    {
        $this->validate();

        return Agent::create([
            'name' => $this->name,
        ]);
    }

    public function update(): bool
    {
        $this->validate();

        return $this->agent->update([
            'name' => $this->name,
        ]);
    }
}
