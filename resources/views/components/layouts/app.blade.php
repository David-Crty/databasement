<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}/">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        function applyTheme() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme) {
                document.documentElement.setAttribute('data-theme', savedTheme);
            }
        }

        applyTheme();
        document.addEventListener('livewire:navigated', () => {
            applyTheme();
            console.log('theme changed');
        });
    </script>
</head>
<body class="min-h-screen font-sans antialiased bg-base-200">

{{-- NAVBAR mobile only --}}
<x-nav sticky class="lg:hidden">
    <x-slot:brand>
        <x-app-brand />
    </x-slot:brand>
    <x-slot:actions>
        <label for="main-drawer" class="lg:hidden me-3">
            <x-icon name="o-bars-3" class="cursor-pointer" />
        </label>
    </x-slot:actions>
</x-nav>

{{-- MAIN --}}
<x-main>
    {{-- SIDEBAR --}}
    <x-slot:sidebar drawer="main-drawer" collapsible class="bg-base-100 lg:bg-inherit">
        <div class="flex flex-col h-full">
            {{-- BRAND --}}
            <x-app-brand class="px-5 pt-4" />

            {{-- MAIN MENU --}}
            <x-menu activate-by-route class="flex-1">
                <x-menu-separator />
                <x-menu-item title="{{ __('Dashboard') }}" icon="o-home" link="{{ route('dashboard') }}" wire:navigate />
                <x-menu-item title="{{ __('Database Servers') }}" icon="o-server-stack" link="{{ route('database-servers.index') }}" wire:navigate />
                <livewire:menu.jobs-menu-item />
                <x-menu-item title="{{ __('Volumes') }}" icon="o-circle-stack" link="{{ route('volumes.index') }}" wire:navigate />
                <x-menu-item title="{{ __('Users') }}" icon="o-users" link="{{ route('users.index') }}" wire:navigate />
                <x-menu-separator />
                <x-menu-item title="{{ __('Configuration') }}" icon="o-cog-6-tooth" link="{{ route('configuration.index') }}" wire:navigate />
                <x-menu-item title="{{ __('API Docs') }}" no-wire-navigate="true" icon="o-document-text" link="{{ route('scramble.docs.ui') }}" />
            </x-menu>

            {{-- USER SECTION AT BOTTOM --}}
            @if($user = auth()->user())
                <x-menu activate-by-route class="mt-auto" title="">
                    <x-menu-sub title="{{ $user->name }}"  icon="o-user">
                        <x-menu-item title="{{ __('Appearance') }}" icon="o-paint-brush" link="{{ route('appearance.edit') }}" wire:navigate />
                        @unless($user->isDemo())
                            <x-menu-item title="{{ __('Profile') }}" icon="o-user" link="{{ route('profile.edit') }}" wire:navigate />
                            @unless($user->isOAuthOnly())
                                <x-menu-item title="{{ __('Password') }}" icon="o-key" link="{{ route('user-password.edit') }}" wire:navigate />
                                @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                                    <x-menu-item title="{{ __('Two-Factor Auth') }}" icon="o-shield-check" link="{{ route('two-factor.show') }}" wire:navigate />
                                @endif
                            @endunless
                            <x-menu-item title="{{ __('API Tokens') }}" icon="o-key" link="{{ route('api-tokens.index') }}" wire:navigate />
                        @endunless
                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <x-button type="submit" class="w-full" icon="o-power">
                                {{ __('Logout') }}
                            </x-button>
                        </form>
                    </x-menu-sub>
                </x-menu>
            @endif
        </div>
    </x-slot:sidebar>

    {{-- The `$slot` goes here --}}
    <x-slot:content>
        {{-- Demo mode banner --}}
        @if (config('app.demo_mode') && auth()->user()?->isDemo())
            <x-alert :title="__('You\'re in demo mode. Some data modifications are disabled.')" class="alert-warning mb-4" icon="o-eye" />
        @endif

        @if (session('status'))
            <x-alert class="alert-success mb-4" icon="o-check-circle" dismissible>
                {{ session('status') }}
            </x-alert>
        @endif

        @if (session('demo_notice'))
            <x-alert class="alert-warning mb-4" icon="o-exclamation-triangle" dismissible>
                {{ session('demo_notice') }}
            </x-alert>
        @endif

        {{ $slot }}

        {{-- FOOTER --}}
        @php
            $commitHash = \App\Support\GitInfo::getCommitHash();
            $commitUrl = \App\Support\GitInfo::getCommitUrl();
            $githubRepo = \App\Support\GitInfo::getGitHubRepo();
            $githubRepoShort = \App\Support\GitInfo::getGitHubRepoShort();
            $newIssueUrl = \App\Support\GitInfo::getNewIssueUrl();
        @endphp
        <footer class="mt-12 py-6 border-t border-base-300">
            <div class="flex flex-col items-center gap-4 text-sm text-base-content/60">
                {{-- Top row: Made by + GitHub --}}
                <div class="flex flex-col sm:flex-row items-center gap-2 sm:gap-4">
                    <span>
                        Made with <span class="text-error">&#10084;</span> by
                        <a href="https://crty.dev" target="_blank" rel="noopener" class="link link-hover">David-Crty</a>
                    </span>
                    <a href="{{ $githubRepo }}" target="_blank" rel="noopener" class="link link-hover flex items-center gap-1">
                        <x-fab-github class="w-4 h-4" />
                        {{ $githubRepoShort }}
                    </a>
                </div>
                {{-- Bottom row: Links --}}
                <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-2">
                    <a href="https://david-crty.github.io/databasement/" target="_blank" rel="noopener" class="link link-hover">
                        Documentation
                    </a>
                    <a href="{{ $newIssueUrl }}" target="_blank" rel="noopener" class="link link-hover">
                        Report an issue
                    </a>
                    <a href="{{ $githubRepo }}/blob/main/LICENSE" target="_blank" rel="noopener" class="link link-hover">
                        MIT License
                    </a>
                    @if($commitHash)
                        <a href="{{ $commitUrl }}" target="_blank" rel="noopener" class="link link-hover font-mono text-xs">
                            {{ $commitHash }}
                        </a>
                    @endif
                </div>
            </div>
        </footer>
    </x-slot:content>
</x-main>

{{--  TOAST area --}}
<x-toast />
</body>
</html>
