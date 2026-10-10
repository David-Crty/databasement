<div>
    <x-header :title="__('Edit Agent')" :subtitle="__('Update agent configuration')" size="text-2xl" separator class="mb-6">
        <x-slot:actions>
            <x-button :label="__('Back')" link="{{ route('agents.index') }}" wire:navigate icon="o-arrow-left" class="btn-ghost" />
        </x-slot:actions>
    </x-header>


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
                spinner
            />
        </div>
    </x-card>

    <!-- REGENERATE TOKEN CONFIRMATION MODAL -->
    <x-confirm-modal
        model="showRegenerateModal"
        :title="__('Regenerate Token')"
        :message="__('This will revoke the current token. The agent will need to be reconfigured with the new token.')"
        on-confirm="regenerateToken"
        :confirm-label="__('Regenerate')"
        confirm-class="btn-warning"
    />

    @include('livewire.agent._token-modal')
</div>
