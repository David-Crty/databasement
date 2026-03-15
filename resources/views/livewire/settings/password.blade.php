<div>
    <x-header title="{{ __('Password') }}" subtitle="{{ __('Ensure your account is using a long, random password to stay secure') }}" size="text-2xl" separator class="mb-6" />

    @if (session('success'))
        <x-alert class="alert-success mb-6" icon="o-check-circle" dismissible>
            {{ session('success') }}
        </x-alert>
    @endif

    <x-card>
        <form wire:submit="updatePassword" class="space-y-6">
            <x-password
                wire:model="current_password"
                label="{{ __('Current password') }}"
                required
                autocomplete="current-password"
            />
            <x-password
                wire:model="password"
                label="{{ __('New password') }}"
                required
                autocomplete="new-password"
            />
            <x-password
                wire:model="password_confirmation"
                label="{{ __('Confirm Password') }}"
                required
                autocomplete="new-password"
            />

            <div class="flex items-center justify-end">
                <x-button type="submit" class="btn-primary" label="{{ __('Save') }}" data-test="update-password-button" />
            </div>
        </form>
    </x-card>
</div>
