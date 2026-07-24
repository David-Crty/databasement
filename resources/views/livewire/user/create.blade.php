<div>
    <x-header :title="__('Add User')" :subtitle="__('Invite a new user or add an existing one to this organization')" size="text-2xl" separator class="mb-6">
        <x-slot:actions>
            <x-button :label="__('Cancel')" link="{{ route('users.index') }}" wire:navigate icon="o-arrow-left" class="btn-ghost" />
        </x-slot:actions>
    </x-header>

    <x-card class="space-y-6">
        @if($this->hasMultipleOrganizations)
            <x-radio-card-group class="grid-cols-1 sm:grid-cols-2">
                <x-radio-card
                    :active="$mode === 'invite'"
                    icon="o-envelope"
                    :label="__('Invite new user')"
                    :hint="__('Create a new account and send an invitation link')"
                    value="invite"
                    horizontal
                    wire:model.live="mode"
                />
                <x-radio-card
                    :active="$mode === 'existing'"
                    icon="o-user-plus"
                    :label="__('Add existing user')"
                    :hint="__('Add a user who already has an account to this organization')"
                    value="existing"
                    horizontal
                    wire:model.live="mode"
                />
            </x-radio-card-group>
        @endif

        @if($mode === 'invite')
            <form wire:submit="save" class="space-y-6">
                @include('livewire.user._form', [
                    'roleOptions' => $roleOptions,
                    'abilityGroups' => $abilityGroups,
                ])

                <div class="flex justify-end gap-3">
                    <x-button :label="__('Cancel')" link="{{ route('users.index') }}" wire:navigate />
                    <x-button type="submit" :label="__('Invite User')" class="btn-primary" spinner="save" />
                </div>
            </form>
        @else
            <form wire:submit="addExisting" class="space-y-6">
                <x-select
                    wire:model="existingUserId"
                    :label="__('User')"
                    :options="$availableUsers"
                    :placeholder="__('Select a user')"
                    icon="o-user"
                    required
                />

                @include('livewire.user._role-cards', [
                    'roleOptions' => $roleOptions,
                    'selected' => $existingUserRole,
                    'model' => 'existingUserRole',
                ])

                <div class="flex justify-end gap-3">
                    <x-button :label="__('Cancel')" link="{{ route('users.index') }}" wire:navigate />
                    <x-button type="submit" :label="__('Add to Organization')" class="btn-primary" spinner="addExisting" />
                </div>
            </form>
        @endif
    </x-card>

    <!-- INVITATION LINK MODAL -->
    <x-invitation-link-modal
        :title="__('User Created Successfully')"
        :message="__('The user has been created. Copy the invitation link below and send it to the user so they can set their password and complete registration.')"
        doneAction="closeAndRedirect"
    />
</div>
