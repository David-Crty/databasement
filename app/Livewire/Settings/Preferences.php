<?php

namespace App\Livewire\Settings;

use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Appearance & Language')]
class Preferences extends Component
{
    use Toast;

    public string $locale = '';

    public string $theme = 'dark';

    public function mount(): void
    {
        $this->locale = app()->getLocale();
        $theme = request()->cookie('theme');
        $this->theme = is_string($theme) ? $theme : 'dark';
    }

    public function setLocale(string $locale): void
    {
        /** @var array<string, string> $available */
        $available = config('app.available_locales', []);

        if (! array_key_exists($locale, $available)) {
            return;
        }

        $this->locale = $locale;

        cookie()->queue('locale', $locale, 60 * 24 * 365);

        $this->success(
            title: __('Preference saved successfully!'),
            redirectTo: route('preferences.edit')
        );
    }

    public function setTheme(string $theme): void
    {
        $this->theme = $theme;

        cookie()->queue('theme', $theme, 60 * 24 * 365);

        $this->skipRender();
    }

    public function render(): View
    {
        return view('livewire.settings.preferences', [
            'availableLocales' => config('app.available_locales', []),
        ]);
    }
}
