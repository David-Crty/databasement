<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Mary\Traits\Toast;

#[Lazy]
class VersionStatus extends Component
{
    use Toast;

    public bool $showModal = false;

    public string $dockerComposeCommand = "docker compose pull\ndocker compose up -d";

    public string $helmCommand = "helm repo update\nhelm upgrade databasement databasement/databasement";

    public string $dockerCommand = "docker pull davidcrty/databasement:1\ndocker stop databasement && docker rm databasement\ndocker run -d \\\n  --name databasement \\\n  -p 2226:2226 \\\n  --env-file .env \\\n  -v ./databasement-data:/data \\\n  davidcrty/databasement:1";

    #[Locked]
    public ?string $latestVersion = null;

    #[Locked]
    public ?string $releaseUrl = null;

    #[Locked]
    public ?string $currentVersion = null;

    public function mount(): void
    {
        $this->getCurrentVersion();

        if (config('app.version')) {
            $this->loadLatestRelease();
        }
    }

    private function getCurrentVersion(): void
    {
        if ($version = config('app.version')) {
            $this->currentVersion = str_starts_with($version, 'v') ? $version : 'v'.$version;
        } elseif (config('app.commit_hash')) {
            $this->currentVersion = config('app.commit_hash');
        } elseif ($gitHash = $this->getGitShortHash()) {
            $this->currentVersion = $gitHash;
        }
    }

    public function placeholder(): View
    {
        $this->getCurrentVersion();

        return view('livewire.version-status-placeholder');
    }

    public function open(): void
    {
        $this->showModal = true;
    }

    public function render(): View
    {
        return view('livewire.version-status');
    }

    private function loadLatestRelease(): void
    {
        $cacheKey = 'github_latest_release';
        $cached = Cache::get($cacheKey);

        if (is_string($cached)) {
            $this->latestVersion = $cached === '' ? null : $cached;
            $this->releaseUrl = $this->latestVersion ? $this->releaseUrl($this->latestVersion) : null;

            return;
        }

        try {
            $response = Http::timeout(3)
                ->get($this->githubApiUrl());

            if ($response->successful()) {
                $this->latestVersion = $response->json('tag_name');
                $this->releaseUrl = $this->latestVersion ? $this->releaseUrl($this->latestVersion) : null;
            }
        } catch (\Throwable) {
            // Silently fail
        }

        // Cache both success (version string) and failure (empty string) to avoid retrying on every page load
        Cache::put($cacheKey, $this->latestVersion ?? '', now()->addDay());
    }

    private function releaseUrl(string $tag): string
    {
        return config('app.github_repo').'/releases/tag/'.$tag;
    }

    private function githubApiUrl(): string
    {
        $repo = config('app.github_repo');
        $path = trim(str_replace('https://github.com/', '', $repo), '/');

        return "https://api.github.com/repos/{$path}/releases/latest";
    }

    private function getGitShortHash(): ?string
    {
        $command = 'rev-parse --short HEAD';

        $output = [];
        $exitCode = 0;
        exec('git -C '.escapeshellarg(base_path())." {$command} 2>/dev/null", $output, $exitCode);

        return $exitCode === 0 && ! empty($output[0]) ? trim($output[0]) : null;
    }
}
