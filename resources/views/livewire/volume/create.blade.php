<div>
    <x-header title="{{ __('Create Volume') }}" subtitle="{{ __('Add a new storage volume for backups') }}" size="text-2xl" separator class="mb-6">
        <x-slot:actions>
            <x-button :label="__('Back')" link="{{ route('volumes.index') }}" wire:navigate icon="o-arrow-left" class="btn-ghost" />
        </x-slot:actions>
    </x-header>


    <x-card class="space-y-6">
        @include('livewire.volume._form', [
            'form' => $form,
            'submitLabel' => 'Create Volume',
        ])
    </x-card>
</div>
