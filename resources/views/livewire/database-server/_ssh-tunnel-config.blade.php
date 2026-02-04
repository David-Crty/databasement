@props(['form', 'isEdit' => false])

@php
    $sshConfigOptions = $form->getSshConfigOptions();
    $hasExistingConfigs = count($sshConfigOptions) > 0;
    $isExistingConfig = $form->ssh_config_mode === 'existing' && $form->ssh_config_id;
    $credentialsOptional = $isEdit || $isExistingConfig;
@endphp

<!-- SSH Tunnel Configuration -->
<div class="mt-4 border border-base-300 rounded-lg bg-base-200">
    <!-- Toggle Header -->
    <label class="flex items-center gap-3 p-4 cursor-pointer">
        <x-toggle
            wire:model.live="form.ssh_enabled"
            class="toggle-primary"
        />
        <span class="font-medium">{{ __('Use SSH Tunnel') }}</span>
    </label>

    <!-- SSH Configuration Form (shown only when enabled) -->
    @if($form->ssh_enabled)
        <div class="border-t border-base-300 bg-base-100 p-4 rounded-b-lg">
            <div class="space-y-4">
                <!-- SSH Configuration Mode -->
                @if($hasExistingConfigs)
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text font-medium">{{ __('SSH Configuration') }}</span>
                        </label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="cursor-pointer">
                                <input type="radio" name="ssh_config_mode" value="existing"
                                       wire:model.live="form.ssh_config_mode" class="peer hidden">
                                <div class="card bg-base-200 border-2 border-transparent peer-checked:border-primary peer-checked:bg-primary/5 transition-all duration-200 hover:bg-base-300">
                                    <div class="card-body p-4 flex-row items-center gap-3">
                                        <div class="w-4 h-4 rounded-full border-2 border-base-content/30 peer-checked:border-primary flex items-center justify-center">
                                            <div class="w-2 h-2 rounded-full bg-primary {{ $form->ssh_config_mode === 'existing' ? 'scale-100' : 'scale-0' }} transition-transform"></div>
                                        </div>
                                        <div>
                                            <div class="font-medium text-sm">{{ __('Use existing') }}</div>
                                            <div class="text-xs text-base-content/60">{{ __('Select from saved configs') }}</div>
                                        </div>
                                    </div>
                                </div>
                            </label>
                            <label class="cursor-pointer">
                                <input type="radio" name="ssh_config_mode" value="create"
                                       wire:model.live="form.ssh_config_mode" class="peer hidden">
                                <div class="card bg-base-200 border-2 border-transparent peer-checked:border-primary peer-checked:bg-primary/5 transition-all duration-200 hover:bg-base-300">
                                    <div class="card-body p-4 flex-row items-center gap-3">
                                        <div class="w-4 h-4 rounded-full border-2 border-base-content/30 peer-checked:border-primary flex items-center justify-center">
                                            <div class="w-2 h-2 rounded-full bg-primary {{ $form->ssh_config_mode === 'create' ? 'scale-100' : 'scale-0' }} transition-transform"></div>
                                        </div>
                                        <div>
                                            <div class="font-medium text-sm">{{ __('Create new') }}</div>
                                            <div class="text-xs text-base-content/60">{{ __('Set up a new SSH config') }}</div>
                                        </div>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>

                    @if($form->ssh_config_mode === 'existing')
                        <x-select
                            wire:model.live="form.ssh_config_id"
                            label="{{ __('Select SSH Configuration') }}"
                            :options="$sshConfigOptions"
                            option-value="id"
                            option-label="name"
                            required
                        />
                    @endif
                @endif

                <!-- SSH Host, Port & Username -->
                <div class="grid grid-cols-1 sm:grid-cols-6 gap-3">
                    <div class="sm:col-span-3">
                        <x-input
                            wire:model="form.ssh_host"
                            label="{{ __('SSH Host') }}"
                            placeholder="{{ __('bastion.example.com') }}"
                            type="text"
                            required
                        />
                    </div>
                    <div class="sm:col-span-1">
                        <x-input
                            wire:model="form.ssh_port"
                            label="{{ __('Port') }}"
                            placeholder="22"
                            type="number"
                            min="1"
                            max="65535"
                            required
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input
                            wire:model="form.ssh_username"
                            label="{{ __('Username') }}"
                            placeholder="{{ __('ssh_user') }}"
                            type="text"
                            required
                            autocomplete="off"
                        />
                    </div>
                </div>

                <!-- Authentication Method -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text font-medium">{{ __('Authentication Method') }}</span>
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <label class="cursor-pointer">
                            <input type="radio" name="ssh_auth_type" value="password"
                                   wire:model.live="form.ssh_auth_type" class="peer hidden">
                            <div class="btn btn-sm peer-checked:btn-primary peer-checked:btn-active btn-outline gap-2">
                                <x-icon name="o-key" class="w-4 h-4" />
                                {{ __('Password') }}
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="ssh_auth_type" value="key"
                                   wire:model.live="form.ssh_auth_type" class="peer hidden">
                            <div class="btn btn-sm peer-checked:btn-primary peer-checked:btn-active btn-outline gap-2">
                                <x-icon name="o-finger-print" class="w-4 h-4" />
                                {{ __('Private Key') }}
                            </div>
                        </label>
                    </div>
                </div>

                @if($form->ssh_auth_type === 'password')
                    <x-password
                        wire:model="form.ssh_password"
                        label="{{ __('SSH Password') }}"
                        placeholder="{{ $credentialsOptional ? __('Leave blank to keep current') : __('SSH password') }}"
                        :required="!$credentialsOptional"
                        autocomplete="off"
                    />
                @else
                    <x-textarea
                        wire:model="form.ssh_private_key"
                        label="{{ __('Private Key') }}"
                        placeholder="{{ $credentialsOptional ? __('Leave blank to keep current') : __('Paste your private key here...') }}"
                        hint="{{ __('OpenSSH format (begins with -----BEGIN OPENSSH PRIVATE KEY-----)') }}"
                        rows="3"
                        class="font-mono text-xs"
                        :required="!$credentialsOptional"
                    />
                    <x-password
                        wire:model="form.ssh_key_passphrase"
                        label="{{ __('Key Passphrase') }}"
                        placeholder="{{ __('Enter passphrase if key is encrypted') }}"
                        hint="{{ __('Only required if your private key is encrypted') }}"
                        autocomplete="off"
                    />
                @endif

                <!-- Test SSH Connection -->
                <div class="flex flex-wrap items-center gap-2">
                    <x-button
                        class="btn-sm {{ $form->sshTestSuccess ? 'btn-success' : 'btn-outline' }}"
                        type="button"
                        icon="{{ $form->sshTestSuccess ? 'o-check-circle' : 'o-signal' }}"
                        wire:click="testSshConnection"
                        spinner="testSshConnection"
                    >
                        @if($form->sshTestSuccess)
                            {{ __('Connected') }}
                        @else
                            {{ __('Test SSH') }}
                        @endif
                    </x-button>

                    @if($form->sshTestMessage && !$form->sshTestSuccess)
                        <span class="text-error text-xs">{{ $form->sshTestMessage }}</span>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
