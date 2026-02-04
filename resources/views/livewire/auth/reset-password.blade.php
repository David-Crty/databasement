<x-layouts::auth>
    <div class="flex flex-col gap-6">
        <div class="flex w-full flex-col text-center">
            <h1 class="text-2xl font-bold">{{ __('Reset password') }}</h1>
            <p class="text-sm opacity-70">{{ __('Please enter your new password below') }}</p>
        </div>

        @if (session('status'))
            <x-alert class="alert-success" icon="o-check-circle">{{ session('status') }}</x-alert>
        @endif

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Token -->
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <!-- Email Address -->
            <x-input
                name="email"
                value="{{ request('email') }}"
                label="{{ __('Email') }}"
                type="email"
                required
                autocomplete="email"
            />

            <!-- Password -->
            <x-password
                name="password"
                label="{{ __('Password') }}"
                required
                autocomplete="new-password"
                placeholder="{{ __('Password') }}"
            />

            <!-- Confirm Password -->
            <x-password
                name="password_confirmation"
                label="{{ __('Confirm password') }}"
                required
                autocomplete="new-password"
                placeholder="{{ __('Confirm password') }}"
            />

            <div class="flex items-center justify-end">
                <x-button type="submit" class="btn-primary w-full" label="{{ __('Reset password') }}" data-test="reset-password-button" />
            </div>
        </form>
    </div>
</x-layouts::auth>
