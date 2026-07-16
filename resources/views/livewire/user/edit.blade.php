<div>
    <x-header title="{{ __('Edit User') }}" subtitle="{{ __('Update user information') }}" size="text-2xl" separator class="mb-6">
        <x-slot:actions>
            <x-button label="{{ __('Back') }}" link="{{ route('users.index') }}" wire:navigate icon="o-arrow-left" class="btn-ghost" />
        </x-slot:actions>
    </x-header>


    <x-card class="space-y-6">
        <form wire:submit="save" class="space-y-6">
            @include('livewire.user._form', [
                'roleOptions' => $roleOptions,
                'abilityGroups' => $abilityGroups,
                'isOAuthUser' => $isOAuthUser,
            ])

            <div class="bg-base-200 p-4 rounded-lg">
                <h4 class="font-medium mb-2">{{ __('User Status') }}</h4>
                <div class="flex items-center gap-2">
                    @if($form->user->isActive())
                        <x-badge value="{{ __('Active') }}" class="badge-success" />
                        <span class="text-sm text-base-content/70">{{ __('Joined :date', ['date' => \App\Support\Formatters::humanDate($form->user->invitation_accepted_at)]) }}</span>
                    @else
                        <x-badge value="{{ __('Pending') }}" class="badge-warning" />
                        <span class="text-sm text-base-content/70">{{ __('Invitation sent, awaiting registration') }}</span>
                    @endif
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <x-button label="{{ __('Cancel') }}" link="{{ route('users.index') }}" wire:navigate />
                <x-button type="submit" label="{{ __('Save Changes') }}" class="btn-primary" spinner="save" />
            </div>
        </form>
    </x-card>
</div>
