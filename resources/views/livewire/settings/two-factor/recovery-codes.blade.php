<div
    class="py-6 space-y-6 border shadow-sm rounded-xl border-base-300"
    wire:cloak
    x-data="{ showRecoveryCodes: false }"
>
    <div class="px-6 space-y-2">
        <div class="flex items-center gap-2">
            <x-icon name="o-lock-closed" class="w-4 h-4" />
            <h3 class="text-lg font-semibold">{{ __('2FA Recovery Codes') }}</h3>
        </div>
        <p class="text-sm opacity-70">
            {{ __('Recovery codes let you regain access if you lose your 2FA device. Store them in a secure password manager.') }}
        </p>
    </div>

    <div class="px-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <x-button
                x-show="!showRecoveryCodes"
                icon="o-eye"
                class="btn-primary"
                @click="showRecoveryCodes = true;"
                label="{{ __('View Recovery Codes') }}"
            />

            <x-button
                x-show="showRecoveryCodes"
                icon="o-eye-slash"
                class="btn-primary"
                @click="showRecoveryCodes = false"
                label="{{ __('Hide Recovery Codes') }}"
            />

            @if (filled($recoveryCodes))
                <x-button
                    x-show="showRecoveryCodes"
                    icon="o-arrow-path"
                    class="btn-outline"
                    wire:click="regenerateRecoveryCodes"
                    spinner
                    label="{{ __('Regenerate Codes') }}"
                />
            @endif
        </div>

        <div
            x-show="showRecoveryCodes"
            x-transition
            id="recovery-codes-section"
            class="relative overflow-hidden"
            x-bind:aria-hidden="!showRecoveryCodes"
        >
            <div class="mt-3 space-y-3">
                @error('recoveryCodes')
                    <x-alert class="alert-error" icon="o-x-circle">{{ $message }}</x-alert>
                @enderror

                @if (filled($recoveryCodes))
                    <div
                        class="grid gap-1 p-4 font-mono text-sm rounded-lg bg-base-200"
                        role="list"
                        aria-label="{{ __('Recovery codes') }}"
                    >
                        @foreach($recoveryCodes as $code)
                            <div
                                role="listitem"
                                class="select-text"
                                wire:loading.class="opacity-50 animate-pulse"
                            >
                                {{ $code }}
                            </div>
                        @endforeach
                    </div>
                    <p class="text-xs opacity-70">
                        {{ __('Each recovery code can be used once to access your account and will be removed after use. If you need more, click Regenerate Codes above.') }}
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>
