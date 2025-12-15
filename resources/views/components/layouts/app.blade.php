<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

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
                <x-menu-item title="{{ __('Jobs') }}" icon="o-queue-list" link="{{ route('jobs.index') }}" wire:navigate />
                <x-menu-item title="{{ __('Snapshots') }}" icon="o-camera" link="{{ route('snapshots.index') }}" wire:navigate />
                <x-menu-item title="{{ __('Volumes') }}" icon="o-circle-stack" link="{{ route('volumes.index') }}" wire:navigate />
                <x-menu-item title="{{ __('Users') }}" icon="o-users" link="{{ route('users.index') }}" wire:navigate />
                <x-menu-separator />
                <x-menu-item title="{{ __('API Docs') }}" icon="o-document-text" link="{{ route('scribe') }}" />
            </x-menu>

            {{-- USER SECTION AT BOTTOM --}}
            @if($user = auth()->user())
                <x-menu activate-by-route class="mt-auto" title="">
                    <x-menu-sub title="{{ $user->name }}"  icon="o-user">
                        <x-menu-item title="{{ __('Profile') }}" icon="o-user" link="{{ route('profile.edit') }}" wire:navigate />
                        <x-menu-item title="{{ __('Password') }}" icon="o-key" link="{{ route('user-password.edit') }}" wire:navigate />
                        @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                            <x-menu-item title="{{ __('Two-Factor Auth') }}" icon="o-shield-check" link="{{ route('two-factor.show') }}" wire:navigate />
                        @endif
                        <x-menu-item title="{{ __('Appearance') }}" icon="o-paint-brush" link="{{ route('appearance.edit') }}" wire:navigate />
                        <x-menu-item title="{{ __('API Tokens') }}" icon="o-key" link="{{ route('api-tokens.index') }}" wire:navigate />

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
        {{ $slot }}
    </x-slot:content>
</x-main>

{{--  TOAST area --}}
<x-toast />
</body>
</html>
