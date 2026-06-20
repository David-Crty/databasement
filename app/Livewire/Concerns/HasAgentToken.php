<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Locked;

trait HasAgentToken
{
    public bool $showTokenModal = false;

    #[Locked]
    public ?string $newToken = null;

    #[Locked]
    public ?string $dockerCommand = null;

    #[Locked]
    public ?string $envVars = null;

    public function showTokenModal(string $plainTextToken): void
    {
        $this->newToken = $plainTextToken;

        $url = config('app.url');

        $this->dockerCommand = implode("\n", [
            'docker run -d \\',
            "  -e DATABASEMENT_URL='{$url}' \\",
            "  -e DATABASEMENT_AGENT_TOKEN='{$this->newToken}' \\",
            '  --name databasement-agent \\',
            '  davidcrty/databasement:latest',
        ]);

        $this->envVars = implode("\n", [
            "DATABASEMENT_URL={$url}",
            "DATABASEMENT_AGENT_TOKEN={$this->newToken}",
        ]);

        $this->showTokenModal = true;
    }

    public function closeTokenModal(): void
    {
        $this->resetTokenModal();
    }

    protected function resetTokenModal(): void
    {
        $this->newToken = null;
        $this->dockerCommand = null;
        $this->envVars = null;
        $this->showTokenModal = false;
    }
}
