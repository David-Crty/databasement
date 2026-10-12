<?php

namespace App\Livewire\Agent;

use App\Models\Agent;
use App\Services\CurrentOrganization;

class Form extends \Livewire\Form
{
    public ?Agent $agent = null;

    public string $name = '';

    /**
     * Move backup files through this server instead of letting the agent
     * reach the storage volumes itself.
     */
    public bool $transfer_via_server = false;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'transfer_via_server' => 'boolean',
        ];
    }

    public function setAgent(Agent $agent): void
    {
        $this->agent = $agent;
        $this->name = $agent->name;
        $this->transfer_via_server = $agent->transfer_via_server;
    }

    public function store(): Agent
    {
        $this->validate();

        return Agent::create([
            'name' => $this->name,
            'transfer_via_server' => $this->transfer_via_server,
            'organization_id' => app(CurrentOrganization::class)->id(),
        ]);
    }

    public function update(): bool
    {
        $this->validate();

        return $this->agent->update([
            'name' => $this->name,
            'transfer_via_server' => $this->transfer_via_server,
        ]);
    }
}
