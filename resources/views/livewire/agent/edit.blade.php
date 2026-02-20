<div>
    <div class="mx-auto max-w-4xl">
        <x-header :title="__('Edit Agent')" :subtitle="__('Update agent configuration')" size="text-2xl" separator class="mb-6" />

        @if (session('status'))
            <x-alert class="alert-success mb-6" icon="o-check-circle" dismissible>
                {{ session('status') }}
            </x-alert>
        @endif

        <x-card class="space-y-6">
            @include('livewire.agent._form', [
                'form' => $form,
                'submitLabel' => __('Update Agent'),
            ])
        </x-card>

        <!-- Token Management -->
        <x-card class="mt-6">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="font-semibold">{{ __('API Token') }}</h3>
                    <p class="text-sm text-base-content/70">{{ __('Regenerate the agent token if compromised.') }}</p>
                </div>
                <x-button
                    :label="__('Regenerate Token')"
                    icon="o-arrow-path"
                    class="btn-warning btn-sm"
                    wire:click="regenerateToken"
                    wire:confirm="{{ __('This will revoke the current token. The agent will need to be reconfigured. Continue?') }}"
                    spinner="regenerateToken"
                />
            </div>
        </x-card>
    </div>

    @include('livewire.agent._token-modal')
</div>
