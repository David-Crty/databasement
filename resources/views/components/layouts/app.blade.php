<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
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

        {{-- BRAND --}}
        <x-app-brand class="px-5 pt-4" />

        <x-menu activate-by-route>
            {{-- User --}}
            @if($user = auth()->user())
                <x-menu-separator />

                <x-list-item :item="$user" value="name" sub-value="email" no-separator no-hover class="-mx-2 !-my-2 rounded">
                    <x-slot:actions>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-button icon="o-power" type="submit" class="btn-circle btn-ghost btn-xs" tooltip-left="logoff" />
                        </form>
                    </x-slot:actions>
                </x-list-item>

                <x-menu-separator />
            @endif

            <x-menu-item title="{{ __('Dashboard') }}" icon="o-home" link="{{ route('dashboard') }}" wire:navigate />
            <x-menu-item title="{{ __('Database Servers') }}" icon="o-server-stack" link="{{ route('database-servers.index') }}" wire:navigate />
            <x-menu-item title="{{ __('Snapshots') }}" icon="o-camera" link="{{ route('snapshots.index') }}" wire:navigate />
            <x-menu-item title="{{ __('Volumes') }}" icon="o-circle-stack" link="{{ route('volumes.index') }}" wire:navigate />
            <x-menu-sub title="Settings" icon="o-cog-6-tooth">
                <x-menu-item title="{{ __('Profile') }}" icon="o-user" link="{{ route('profile.edit') }}" wire:navigate />
                <x-menu-item title="{{ __('Password') }}" icon="o-key" link="{{ route('user-password.edit') }}" wire:navigate />
                @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                    <x-menu-item title="{{ __('Two-Factor Auth') }}" icon="o-shield-check" link="{{ route('two-factor.show') }}" wire:navigate />
                @endif
                <x-menu-item title="{{ __('Appearance') }}" icon="o-paint-brush" link="{{ route('appearance.edit') }}" wire:navigate />
            </x-menu-sub>
        </x-menu>
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
