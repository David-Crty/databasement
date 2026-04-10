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

            <div>
                <label class="label label-text font-semibold mb-2">{{ __('Role') }}</label>
                <x-radio-card-group class="grid-cols-1 sm:grid-cols-3" :label="__('Role')">
                    @foreach($roleOptions as $option)
                        <x-radio-card
                            :active="$form->role === $option['id']"
                            :icon="$option['icon']"
                            :label="$option['name']"
                            :hint="$option['description']"
                            :value="$option['id']"
                            horizontal
                            wire:model.live="form.role"
                        />
                    @endforeach
                </x-radio-card-group>
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
