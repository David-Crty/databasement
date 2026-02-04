<x-layouts::auth>
    <div class="flex flex-col gap-6">
        <div class="flex w-full flex-col text-center">
            <h1 class="text-2xl font-bold">{{ __('Forgot password') }}</h1>
            <p class="text-sm opacity-70">{{ __('Enter your email to receive a password reset link') }}</p>
        </div>

        @if (session('status'))
            <x-alert class="alert-success" icon="o-check-circle">{{ session('status') }}</x-alert>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <x-input
                name="email"
                label="{{ __('Email Address') }}"
                type="email"
                required
                autofocus
                placeholder="email@example.com"
            />

            <x-button type="submit" class="btn-primary w-full" label="{{ __('Email password reset link') }}" data-test="email-password-reset-link-button" />
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm">
            <span>{{ __('Or, return to') }}</span>
            <a href="{{ route('login') }}" class="link link-primary" wire:navigate>{{ __('log in') }}</a>
        </div>
    </div>
</x-layouts::auth>
