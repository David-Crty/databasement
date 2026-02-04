<x-layouts::auth>
    <div class="flex flex-col gap-6">
        <div class="flex w-full flex-col text-center">
            <h1 class="text-2xl font-bold">{{ __('Confirm password') }}</h1>
            <p class="text-sm opacity-70">{{ __('This is a secure area of the application. Please confirm your password before continuing.') }}</p>
        </div>

        @if (session('status'))
            <x-alert class="alert-success" icon="o-check-circle">{{ session('status') }}</x-alert>
        @endif

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
            @csrf

            <x-password
                name="password"
                label="{{ __('Password') }}"
                required
                autocomplete="current-password"
                placeholder="{{ __('Password') }}"
            />

            <x-button type="submit" class="btn-primary w-full" label="{{ __('Confirm') }}" data-test="confirm-password-button" />
        </form>
    </div>
</x-layouts::auth>
