<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Changelog')]
class Changelog extends Component
{
    /**
     * CHANGELOG.md rendered to HTML. The file is plain Markdown maintained by
     * the /changelog skill, so the page styles it (see `.changelog` in
     * app.css) rather than picking it apart here.
     */
    #[Computed]
    public function html(): string
    {
        $path = base_path('CHANGELOG.md');

        if (! is_file($path)) {
            return '';
        }

        return Cache::remember(
            'changelog.html.'.filemtime($path),
            now()->addDay(),
            fn (): string => Str::markdown((string) file_get_contents($path), [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]),
        );
    }

    #[Computed]
    public function currentVersion(): ?string
    {
        $version = config('app.version');

        return is_string($version) && $version !== '' ? ltrim($version, 'v') : null;
    }

    public function render(): View
    {
        return view('livewire.changelog');
    }
}
