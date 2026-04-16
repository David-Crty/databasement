<div>
    <x-header title="{{ __('Create Database Server') }}" subtitle="{{ __('Add a new database server to manage backups') }}" size="text-2xl" separator class="mb-6" />


    @include('livewire.database-server._form', [
        'form' => $form,
        'submitLabel' => 'Create Database Server',
        'isEdit' => false,
    ])
</div>
