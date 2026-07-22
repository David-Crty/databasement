<?php

namespace App\Livewire\Agent;

use App\Models\Agent;
use App\Services\CurrentOrganization;

class Form extends \Livewire\Form
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
            'organization_id' => app(CurrentOrganization::class)->id(),
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
