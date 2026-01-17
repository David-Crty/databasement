<div wire:init="testConnection">
    <div class="mx-auto max-w-4xl">
        <x-header title="{{ __('Edit Database Server') }}" subtitle="{{ __('Update your database server configuration') }}" size="text-2xl" separator class="mb-6" />

        @include('livewire.database-server._form', [
            'form' => $form,
            'submitLabel' => 'Update Database Server',
            'isEdit' => true,
        ])
    </div>
</div>
