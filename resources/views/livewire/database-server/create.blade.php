<div>
    <x-header title="{{ __('Create Database Server') }}" subtitle="{{ __('Add a new database server to manage backups') }}" size="text-2xl" separator class="mb-6">
        <x-slot:actions>
            <x-button :label="__('Back')" link="{{ route('database-servers.index') }}" wire:navigate icon="o-arrow-left" class="btn-ghost" />
        </x-slot:actions>
    </x-header>


    @include('livewire.database-server._form', [
        'form' => $form,
        'submitLabel' => 'Create Database Server',
        'isEdit' => false,
    ])
</div>
