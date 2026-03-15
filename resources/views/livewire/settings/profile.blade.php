<div>
    <x-header title="{{ __('Profile') }}" subtitle="{{ __('Update your name and email address') }}" size="text-2xl" separator class="mb-6" />

    @if (session('success'))
        <x-alert class="alert-success mb-6" icon="o-check-circle" dismissible>
            {{ session('success') }}
        </x-alert>
    @endif

    <x-card>
        <form wire:submit="updateProfileInformation" class="space-y-6">
            <x-input wire:model="name" label="{{ __('Name') }}" type="text" required autofocus autocomplete="name" />

            <x-input wire:model="email" label="{{ __('Email') }}" type="email" required autocomplete="email" />

            <div class="flex items-center justify-end">
                <x-button type="submit" class="btn-primary" label="{{ __('Save') }}" data-test="update-profile-button" />
            </div>
        </form>
    </x-card>

    <livewire:settings.delete-user-form />
</div>
