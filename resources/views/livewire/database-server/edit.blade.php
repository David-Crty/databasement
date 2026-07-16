<div wire:init="loadDatabases">
    <x-header title="{{ __('Edit Database Server') }}" subtitle="{{ __('Update your database server configuration') }}" size="text-2xl" separator class="mb-6">
        <x-slot:actions>
            <x-button :label="__('Back')" link="{{ $returnUrl }}" wire:navigate icon="o-arrow-left" class="btn-ghost" />
        </x-slot:actions>
    </x-header>

    @include('livewire.database-server._form', [
        'form' => $form,
        'submitLabel' => 'Update Database Server',
        'isEdit' => true,
    ])
</div>
