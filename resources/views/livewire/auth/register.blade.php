<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <div class="flex w-full flex-col text-center">
            <h1 class="text-2xl font-bold">{{ __('Create an account') }}</h1>
            <p class="text-sm opacity-70">{{ __('Enter your details below to create your account') }}</p>
        </div>

        @if (session('status'))
            <x-alert class="alert-success" icon="o-check-circle">{{ session('status') }}</x-alert>
        @endif

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Name -->
            <x-input
                name="name"
                label="{{ __('Name') }}"
                type="text"
                required
                autofocus
                autocomplete="name"
                placeholder="{{ __('Full name') }}"
            />

            <!-- Email Address -->
            <x-input
                name="email"
                label="{{ __('Email address') }}"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
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

            @if(\App\Models\User::count() === 0)
            <!-- Demo Backup Option -->
            <x-checkbox
                name="create_demo_backup"
                :label="__('Add Databasement\'s own database as a demo backup')"
                :hint="__('Creates a local backup volume and schedules daily backups of this application\'s database')"
                checked
            />
            @endif

            <div class="flex items-center justify-end">
                <x-button type="submit" class="btn-primary w-full" label="{{ __('Create account') }}" data-test="register-user-button" />
            </div>
        </form>
    </div>
</x-layouts.auth>
