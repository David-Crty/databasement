<div>
    <x-header title="{{ __('Create User') }}" subtitle="{{ __('Invite a new user to the application') }}" size="text-2xl" separator class="mb-6">
        <x-slot:actions>
            <x-button label="{{ __('Cancel') }}" link="{{ route('users.index') }}" wire:navigate icon="o-arrow-left" class="btn-ghost" />
        </x-slot:actions>
    </x-header>

    <x-card class="space-y-6">
        <form wire:submit="save" class="space-y-6">
            <x-input
                wire:model="form.name"
                label="{{ __('Name') }}"
                placeholder="{{ __('Full name') }}"
                icon="o-user"
                required
            />

            <x-input
                wire:model="form.email"
                label="{{ __('Email') }}"
                type="email"
                placeholder="{{ __('email@example.com') }}"
                icon="o-envelope"
                required
            />

            <x-select
                wire:model="form.role"
                label="{{ __('Role') }}"
                :options="$roleOptions"
                icon="o-shield-check"
                required
            />

            <div class="bg-base-200 p-4 rounded-lg">
                <h4 class="font-medium mb-2">{{ __('Role Permissions') }}</h4>
                <ul class="text-sm space-y-1 text-base-content/70">
                    <li><strong>{{ __('Viewer') }}:</strong> {{ __('Read-only access to all index pages and details. Cannot perform any actions.') }}</li>
                    <li><strong>{{ __('Member') }}:</strong> {{ __('Full access to create, edit, and delete resources. Cannot manage users.') }}</li>
                    <li><strong>{{ __('Admin') }}:</strong> {{ __('Full access to everything, including user management.') }}</li>
                </ul>
            </div>

            <div class="flex justify-end gap-3">
                <x-button label="{{ __('Cancel') }}" link="{{ route('users.index') }}" wire:navigate />
                <x-button type="submit" label="{{ __('Create User') }}" class="btn-primary" spinner="save" />
            </div>
        </form>
    </x-card>

    <!-- INVITATION LINK MODAL -->
    <x-invitation-link-modal
        :title="__('User Created Successfully')"
        :message="__('The user has been created. Copy the invitation link below and send it to the user so they can set their password and complete registration.')"
        doneAction="closeAndRedirect"
    />
</div>
