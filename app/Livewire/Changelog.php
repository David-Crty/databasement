<?php

namespace App\Livewire;

use App\Support\Changelog\ChangelogParser;
use App\Support\Changelog\ChangelogRelease;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Changelog')]
class Changelog extends Component
{
    /**
     * Unreleased entries only make sense on builds that are not a tagged
     * release, so they are hidden whenever a version is configured.
     *
     * @return list<ChangelogRelease>
     */
    #[Computed]
    public function releases(): array
    {
        $releases = app(ChangelogParser::class)->releases();

        if ($this->currentVersion() === null) {
            return $releases;
        }

        return array_values(array_filter($releases, fn (ChangelogRelease $release): bool => ! $release->isUnreleased()));
    }

    #[Computed]
    public function currentVersion(): ?string
    {
        $version = config('app.version');

        return is_string($version) && $version !== '' ? ltrim($version, 'v') : null;
    }

    public function isCurrent(ChangelogRelease $release): bool
    {
        $current = $this->currentVersion();

        if ($current === null || $release->version === null) {
            return false;
        }

        return $release->version === $current || $release->version === ChangelogRelease::minorOf($current);
    }

    public function isNewerThanCurrent(?string $version): bool
    {
        $current = $this->currentVersion();

        return $version !== null && $current !== null && version_compare($version, $current, '>');
    }

    public function render(): View
    {
        return view('livewire.changelog');
    }
}
