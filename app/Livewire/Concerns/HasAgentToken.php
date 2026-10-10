<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Locked;

trait HasAgentToken
{
    public bool $showTokenModal = false;

    public string $tokenModalTab = 'docker-compose-tab';

    #[Locked]
    public ?string $newToken = null;

    #[Locked]
    public ?string $envVars = null;

    #[Locked]
    public ?string $dockerCommand = null;

    #[Locked]
    public ?string $dockerComposeConfig = null;

    #[Locked]
    public ?string $helmCommand = null;

    public function showTokenModal(string $plainTextToken): void
    {
        $this->newToken = $plainTextToken;

        $url = config('app.url');
        $version = $this->releasedVersion();
        $image = 'davidcrty/databasement:'.($version ? implode('.', array_slice(explode('.', $version), 0, 2)) : '1');

        $this->envVars = implode("\n", [
            "DATABASEMENT_URL={$url}",
            "DATABASEMENT_AGENT_TOKEN={$this->newToken}",
        ]);

        $this->dockerCommand = implode("\n", [
            'docker run -d --restart unless-stopped \\',
            '  --name databasement-agent \\',
            "  -e DATABASEMENT_URL='{$url}' \\",
            "  -e DATABASEMENT_AGENT_TOKEN='{$this->newToken}' \\",
            "  {$image}",
        ]);

        $this->dockerComposeConfig = implode("\n", [
            'services:',
            '  databasement-agent:',
            "    image: {$image}",
            '    restart: unless-stopped',
            '    environment:',
            "      DATABASEMENT_URL: '{$url}'",
            "      DATABASEMENT_AGENT_TOKEN: '{$this->newToken}'",
        ]);

        $chartVersion = $version ? " \\\n  --version {$version}" : '';

        $this->helmCommand = implode("\n", [
            'helm repo add databasement https://david-crty.github.io/databasement',
            'helm repo update',
            'helm install databasement-agent databasement/databasement \\',
            '  --set app.enabled=false \\',
            '  --set agents.main.enabled=true \\',
            "  --set agents.main.url='{$url}' \\",
            "  --set agents.main.token.value='{$this->newToken}'{$chartVersion}",
        ]);

        $this->showTokenModal = true;
    }

    protected function releasedVersion(): ?string
    {
        $version = ltrim((string) config('app.version'), 'v');

        return preg_match('/^\d+\.\d+\.\d+$/', $version) ? $version : null;
    }

    public function closeTokenModal(): void
    {
        $this->resetTokenModal();
    }

    protected function resetTokenModal(): void
    {
        $this->newToken = null;
        $this->envVars = null;
        $this->dockerCommand = null;
        $this->dockerComposeConfig = null;
        $this->helmCommand = null;
        $this->showTokenModal = false;
    }
}
