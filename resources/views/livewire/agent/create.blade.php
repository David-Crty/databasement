<div>
    <x-header :title="__('Create Agent')" :subtitle="__('Add a new remote agent for database backups')" size="text-2xl" separator class="mb-6" />

    <x-card class="space-y-6">
        @include('livewire.agent._form', [
            'form' => $form,
            'submitLabel' => __('Create Agent'),
        ])
    </x-card>

    @include('livewire.agent._token-modal')
</div>
