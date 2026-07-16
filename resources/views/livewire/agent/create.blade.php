<div>
    <x-header :title="__('Create Agent')" :subtitle="__('Add a new remote agent for database backups')" size="text-2xl" separator class="mb-6">
        <x-slot:actions>
            <x-button :label="__('Back')" link="{{ route('agents.index') }}" wire:navigate icon="o-arrow-left" class="btn-ghost" />
        </x-slot:actions>
    </x-header>

    <x-card class="space-y-6">
        @include('livewire.agent._form', [
            'form' => $form,
            'submitLabel' => __('Create Agent'),
        ])
    </x-card>

    @include('livewire.agent._token-modal')
</div>
