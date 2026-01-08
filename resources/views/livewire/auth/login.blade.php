<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <div class="flex w-full flex-col text-center">
            <h1 class="text-2xl font-bold">{{ __('Log in to your account') }}</h1>
            <p class="text-sm opacity-70">{{ __('Enter your email and password below to log in') }}</p>
        </div>

        @if (config('app.demo_mode'))
            <x-alert class="alert-info" icon="o-information-circle">
                {{ __('Demo mode: credentials are pre-filled. Just click Log in!') }}
            </x-alert>
        @endif

        @if (session('status'))
            <x-alert class="alert-success" icon="o-check-circle">{{ session('status') }}</x-alert>
        @endif

        @if (session('error'))
            <x-alert class="alert-error" icon="o-exclamation-circle">{{ session('error') }}</x-alert>
        @endif

        <x-form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <x-input
                name="email"
                label="{{ __('Email address') }}"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
                value="{{ config('app.demo_mode') ? config('app.demo_user_email') : old('email') }}"
            />

            <!-- Password -->
            <div class="relative">
                <x-password
                    name="password"
                    label="{{ __('Password') }}"
                    required
                    autocomplete="current-password"
                    placeholder="{{ __('Password') }}"
                    value="{{ config('app.demo_mode') ? config('app.demo_user_password') : '' }}"
                />

                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="absolute top-0 text-sm end-0 link link-hover" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </a>
                @endif
            </div>

            <!-- Remember Me -->
            <x-checkbox name="remember" label="{{ __('Remember me') }}" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <x-button type="submit" class="btn-primary w-full" label="{{ __('Log in') }}" data-test="login-button" />
            </div>
        </x-form>

        {{-- OAuth Providers Section --}}
        @php
            $oauthProviders = app(\App\Services\OAuthService::class)->getEnabledProviders();
        @endphp

        @if (count($oauthProviders) > 0)
            <div class="divider">{{ __('or continue with') }}</div>

            <div class="flex flex-col gap-3">
                @foreach ($oauthProviders as $key => $provider)
                    <a href="{{ $provider['url'] }}" class="btn btn-outline w-full gap-2" data-test="oauth-{{ $key }}">
                        <x-icon name="{{ $provider['icon'] }}" class="w-5 h-5" />
                        {{ __('Continue with :provider', ['provider' => $provider['label']]) }}
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.auth>
