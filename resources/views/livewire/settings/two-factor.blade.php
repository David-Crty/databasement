<div>
    <x-header title="{{ __('Two Factor Authentication') }}" subtitle="{{ __('Manage your two-factor authentication settings') }}" size="text-2xl" separator class="mb-6" />

    <x-card>
        <div class="flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
            @if ($twoFactorEnabled)
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <span class="badge badge-success">{{ __('Enabled') }}</span>
                    </div>

                    <p>
                        {{ __('With two-factor authentication enabled, you will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.') }}
                    </p>

                    <livewire:settings.two-factor.recovery-codes :requiresConfirmation="$requiresConfirmation" />

                    <div class="flex justify-start">
                        <x-button
                            class="btn-error"
                            icon="o-shield-exclamation"
                            wire:click="disable"
                            label="{{ __('Disable 2FA') }}"
                        />
                    </div>
                </div>
            @else
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <span class="badge badge-error">{{ __('Disabled') }}</span>
                    </div>

                    <p class="opacity-70">
                        {{ __('When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.') }}
                    </p>

                    <x-button
                        class="btn-primary"
                        icon="o-shield-check"
                        wire:click="enable"
                        label="{{ __('Enable 2FA') }}"
                    />
                </div>
            @endif
        </div>
    </x-card>

    <x-modal
        wire:model="showModal"
        title="{{ $this->modalConfig['title'] }}"
        class="backdrop-blur"
    >
        <div class="space-y-6">
            <div class="flex flex-col items-center space-y-4">
                <div class="p-0.5 w-auto rounded-full border border-base-300 bg-base-100 shadow-sm">
                    <div class="p-2.5 rounded-full border border-base-300 overflow-hidden bg-base-200 relative">
                        <x-icon name="o-qr-code" class="w-6 h-6" />
                    </div>
                </div>

                <p class="text-center">{{ $this->modalConfig['description'] }}</p>
            </div>

            @if ($showVerificationStep)
                <div class="space-y-6">
                    <div class="flex flex-col items-center space-y-3">
                        <x-input-otp
                            :digits="6"
                            name="code"
                            wire:model="code"
                            autocomplete="one-time-code"
                        />
                        @error('code')
                            <p class="text-error text-sm">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center space-x-3">
                        <x-button
                            class="btn-outline flex-1"
                            wire:click="resetVerification"
                            label="{{ __('Back') }}"
                        />

                        <x-button
                            class="btn-primary flex-1"
                            wire:click="confirmTwoFactor"
                            x-bind:disabled="$wire.code.length < 6"
                            label="{{ __('Confirm') }}"
                        />
                    </div>
                </div>
            @else
                @error('setupData')
                    <x-alert class="alert-error" icon="o-x-circle">{{ $message }}</x-alert>
                @enderror

                <div class="flex justify-center">
                    <div class="relative w-64 overflow-hidden border rounded-lg border-base-300 aspect-square">
                        @empty($qrCodeSvg)
                            <div class="absolute inset-0 flex items-center justify-center bg-base-200 animate-pulse">
                                <span class="loading loading-spinner"></span>
                            </div>
                        @else
                            <div class="flex items-center justify-center h-full p-4">
                                <div class="bg-white p-3 rounded">
                                    {!! $qrCodeSvg !!}
                                </div>
                            </div>
                        @endempty
                    </div>
                </div>

                <div>
                    <x-button
                        :disabled="$errors->has('setupData')"
                        class="btn-primary w-full"
                        wire:click="showVerificationIfNecessary"
                        label="{{ $this->modalConfig['buttonText'] }}"
                    />
                </div>

                <div class="space-y-4">
                    <div class="divider text-sm">{{ __('or, enter the code manually') }}</div>

                    <div class="flex items-stretch w-full border rounded-xl border-base-300">
                        @empty($manualSetupKey)
                            <div class="flex items-center justify-center w-full p-3 bg-base-200">
                                <span class="loading loading-spinner loading-sm"></span>
                            </div>
                        @else
                            <input
                                type="text"
                                readonly
                                value="{{ $manualSetupKey }}"
                                class="input input-bordered w-full border-0"
                            />

                            <x-button
                                icon="o-clipboard-document"
                                class="btn-ghost px-3"
                                x-clipboard="$wire.manualSetupKey"
                            />
                        @endempty
                    </div>
                </div>
            @endif
        </div>
    </x-modal>
</div>
