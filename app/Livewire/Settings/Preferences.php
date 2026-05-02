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

    /** @var string[] */
    private const ALLOWED_THEMES = [
        'dark', 'light', 'cupcake', 'bumblebee', 'emerald', 'corporate', 'synthwave', 'retro',
        'cyberpunk', 'valentine', 'halloween', 'garden', 'forest', 'aqua', 'lofi', 'pastel',
        'fantasy', 'wireframe', 'black', 'luxury', 'dracula', 'cmyk', 'autumn', 'business',
        'acid', 'lemonade', 'night', 'coffee', 'winter', 'dim', 'nord', 'sunset',
    ];

    public string $locale = '';

    /** @var 'manual'|'auto' */
    public string $themeMode = 'manual';

    public string $theme = 'dark';

    public string $lightTheme = 'light';

    public string $darkTheme = 'dark';

    public function mount(): void
    {
        $this->locale = app()->getLocale();

        $themeMode = request()->cookie('theme_mode');
        $this->themeMode = $themeMode === 'auto' ? 'auto' : 'manual';

        $theme = request()->cookie('theme');
        $this->theme = (is_string($theme) && in_array($theme, self::ALLOWED_THEMES, true)) ? $theme : 'dark';

        $lightTheme = request()->cookie('light_theme');
        $this->lightTheme = (is_string($lightTheme) && in_array($lightTheme, self::ALLOWED_THEMES, true)) ? $lightTheme : 'light';

        $darkTheme = request()->cookie('dark_theme');
        $this->darkTheme = (is_string($darkTheme) && in_array($darkTheme, self::ALLOWED_THEMES, true)) ? $darkTheme : 'dark';
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

    public function setThemeMode(string $mode): void
    {
        if (! in_array($mode, ['manual', 'auto'], strict: true)) {
            return;
        }

        $this->themeMode = $mode;

        cookie()->queue('theme_mode', $mode, 60 * 24 * 365);

        $this->skipRender();
    }

    public function setTheme(string $theme): void
    {
        if (! in_array($theme, self::ALLOWED_THEMES, true)) {
            return;
        }

        $this->theme = $theme;

        cookie()->queue('theme', $theme, 60 * 24 * 365);

        $this->skipRender();
    }

    public function setLightTheme(string $theme): void
    {
        if (! in_array($theme, self::ALLOWED_THEMES, true)) {
            return;
        }

        $this->lightTheme = $theme;

        cookie()->queue('light_theme', $theme, 60 * 24 * 365);

        $this->skipRender();
    }

    public function setDarkTheme(string $theme): void
    {
        if (! in_array($theme, self::ALLOWED_THEMES, true)) {
            return;
        }

        $this->darkTheme = $theme;

        cookie()->queue('dark_theme', $theme, 60 * 24 * 365);

        $this->skipRender();
    }

    public function render(): View
    {
        return view('livewire.settings.preferences', [
            'availableLocales' => config('app.available_locales', []),
            'themes' => self::ALLOWED_THEMES,
        ]);
    }
}
