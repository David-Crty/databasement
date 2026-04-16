<div>
    <x-header :title="__('Edit Agent')" :subtitle="__('Update agent configuration')" size="text-2xl" separator class="mb-6" />


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
                wire:click="confirmRegenerate"
            />
        </div>
    </x-card>

    <!-- REGENERATE TOKEN CONFIRMATION MODAL -->
    <x-modal wire:model="showRegenerateModal" :title="__('Regenerate Token')" class="backdrop-blur">
        <p>{{ __('This will revoke the current token. The agent will need to be reconfigured with the new token.') }}</p>

        <x-slot:actions>
            <x-button :label="__('Cancel')" @click="$wire.showRegenerateModal = false" />
            <x-button :label="__('Regenerate')" class="btn-warning" wire:click="regenerateToken" spinner="regenerateToken" />
        </x-slot:actions>
    </x-modal>

    @include('livewire.agent._token-modal')
</div>
