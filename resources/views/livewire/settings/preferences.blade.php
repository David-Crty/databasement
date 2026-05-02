<div x-data="{
    themeMode: @js($themeMode),
    currentTheme: @js($theme),
    lightTheme: @js($lightTheme),
    darkTheme: @js($darkTheme),

    init() {
        if (this.themeMode === 'auto') {
            this.applyAutoTheme();
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => this.applyAutoTheme());
        }
    },

    applyAutoTheme() {
        const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.setAttribute('data-theme', isDark ? this.darkTheme : this.lightTheme);
    },

    setThemeMode(mode) {
        this.themeMode = mode;
        $wire.setThemeMode(mode);
        if (mode === 'auto') {
            this.applyAutoTheme();
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => this.applyAutoTheme());
        } else {
            document.documentElement.setAttribute('data-theme', this.currentTheme);
        }
    },

    setTheme(theme) {
        this.currentTheme = theme;
        document.documentElement.setAttribute('data-theme', theme);
        $wire.setTheme(theme);
    },

    setLightTheme(theme) {
        this.lightTheme = theme;
        $wire.setLightTheme(theme);
        if (this.themeMode === 'auto') this.applyAutoTheme();
    },

    setDarkTheme(theme) {
        this.darkTheme = theme;
        $wire.setDarkTheme(theme);
        if (this.themeMode === 'auto') this.applyAutoTheme();
    },

    isActive(theme) { return this.currentTheme === theme; },
    isActiveLightTheme(theme) { return this.lightTheme === theme; },
    isActiveDarkTheme(theme) { return this.darkTheme === theme; }
}">
    <div class="mx-auto max-w-7xl">
        <x-header :title="__('Appearance & Language')" :subtitle="__('Customize your display and language preferences')" size="text-2xl" separator class="mb-6" />

        {{-- LANGUAGE --}}
        <x-card :title="__('Language')" :subtitle="__('Choose your preferred language')" class="mb-6">
            <div class="flex flex-wrap gap-3">
                @foreach($availableLocales as $code => $label)
                    <button
                        wire:click="setLocale('{{ $code }}')"
                        wire:key="locale-{{ $code }}"
                        aria-pressed="{{ $locale === $code ? 'true' : 'false' }}"
                        class="btn {{ $locale === $code ? 'btn-primary' : 'btn-outline' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </x-card>

        {{-- THEME MODE --}}
        <x-card :title="__('Theme Mode')" :subtitle="__('Choose how the theme is determined')" class="mb-6">
            <div class="flex flex-wrap gap-3">
                <button
                    @click="setThemeMode('manual')"
                    :class="themeMode === 'manual' ? 'btn-primary' : 'btn-outline'"
                    class="btn gap-2"
                >
                    <x-icon name="o-swatch" class="w-4 h-4" />
                    {{ __('Manual') }}
                </button>
                <button
                    @click="setThemeMode('auto')"
                    :class="themeMode === 'auto' ? 'btn-primary' : 'btn-outline'"
                    class="btn gap-2"
                >
                    <x-icon name="o-computer-desktop" class="w-4 h-4" />
                    {{ __('Auto (System)') }}
                </button>
            </div>
            <p class="text-sm text-base-content/60 mt-3" x-show="themeMode === 'auto'">
                {{ __('The theme switches automatically based on your OS light/dark mode setting.') }}
            </p>
        </x-card>

        @php
        $themes = [
            'dark', 'light', 'cupcake', 'bumblebee', 'emerald', 'corporate', 'synthwave', 'retro',
            'cyberpunk', 'valentine', 'halloween', 'garden', 'forest', 'aqua', 'lofi', 'pastel',
            'fantasy', 'wireframe', 'black', 'luxury', 'dracula', 'cmyk', 'autumn', 'business',
            'acid', 'lemonade', 'night', 'coffee', 'winter', 'dim', 'nord', 'sunset',
        ];
        @endphp

        {{-- MANUAL: single theme picker --}}
        <div x-show="themeMode === 'manual'">
            <x-card :title="__('Theme')" :subtitle="__('Choose your preferred theme')" class="mb-6">
                <div class="rounded-box grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                    @foreach($themes as $themeName)
                        <div class="border-base-content/20 hover:border-base-content/40 overflow-hidden rounded-lg border outline-2 outline-offset-2 transition-all"
                             :class="isActive('{{ $themeName }}') ? 'outline outline-base-content' : 'outline-transparent'"
                             @click="setTheme('{{ $themeName }}')">
                            @include('livewire.settings._theme-swatch', ['themeName' => $themeName])
                        </div>
                    @endforeach
                </div>
            </x-card>
        </div>

        {{-- AUTO: separate light and dark theme pickers --}}
        <div x-show="themeMode === 'auto'" class="space-y-6 mb-6">
            <x-card :title="__('Light Mode Theme')" :subtitle="__('Used when your system is in light mode')">
                <div class="rounded-box grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                    @foreach($themes as $themeName)
                        <div class="border-base-content/20 hover:border-base-content/40 overflow-hidden rounded-lg border outline-2 outline-offset-2 transition-all"
                             :class="isActiveLightTheme('{{ $themeName }}') ? 'outline outline-base-content' : 'outline-transparent'"
                             @click="setLightTheme('{{ $themeName }}')">
                            @include('livewire.settings._theme-swatch', ['themeName' => $themeName])
                        </div>
                    @endforeach
                </div>
            </x-card>

            <x-card :title="__('Dark Mode Theme')" :subtitle="__('Used when your system is in dark mode')">
                <div class="rounded-box grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                    @foreach($themes as $themeName)
                        <div class="border-base-content/20 hover:border-base-content/40 overflow-hidden rounded-lg border outline-2 outline-offset-2 transition-all"
                             :class="isActiveDarkTheme('{{ $themeName }}') ? 'outline outline-base-content' : 'outline-transparent'"
                             @click="setDarkTheme('{{ $themeName }}')">
                            @include('livewire.settings._theme-swatch', ['themeName' => $themeName])
                        </div>
                    @endforeach
                </div>
            </x-card>
        </div>

    </div>
</div>
