<x-layouts::auth>
    <div class="mt-4 flex flex-col gap-6">
        <p class="text-center">
            {{ __('Please verify your email address by clicking on the link we just emailed to you.') }}
        </p>

        @if (session('status') == 'verification-link-sent')
            <p class="text-center font-medium text-success">
                {{ __('A new verification link has been sent to the email address you provided during registration.') }}
            </p>
        @endif

        <div class="flex flex-col items-center justify-between space-y-3">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <x-button type="submit" class="btn-primary w-full" label="{{ __('Resend verification email') }}" />
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-button type="submit" class="btn-ghost text-sm" label="{{ __('Log out') }}" data-test="logout-button" />
            </form>
        </div>
    </div>
</x-layouts::auth>
