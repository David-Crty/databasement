<div>
    <x-header title="{{ __('Edit User') }}" subtitle="{{ __('Update user information') }}" size="text-2xl" separator class="mb-6">
        <x-slot:actions>
            <x-button label="{{ __('Back') }}" link="{{ route('users.index') }}" wire:navigate icon="o-arrow-left" class="btn-ghost" />
        </x-slot:actions>
    </x-header>

    @if (session('status'))
        <x-alert class="alert-success mb-6" icon="o-check-circle" dismissible>
            {{ session('status') }}
        </x-alert>
    @endif

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

            @php
                $isOnlyAdmin = $form->user->isAdmin() && \App\Models\User::where('role', 'admin')->count() === 1;
            @endphp

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
                            :disabled="$isOnlyAdmin && $option['id'] !== \App\Models\User::ROLE_ADMIN"
                            horizontal
                            wire:model.live="form.role"
                        />
                    @endforeach
                </x-radio-card-group>
            </div>

            @if($isOnlyAdmin)
                <x-alert class="alert-warning" icon="o-exclamation-triangle">
                    {{ __('This is the only administrator. The role cannot be changed.') }}
                </x-alert>
            @endif

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
