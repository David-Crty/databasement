<div>
    <div class="mx-auto max-w-4xl">
        <x-header title="{{ __('Create Database Server') }}" subtitle="{{ __('Add a new database server to manage backups') }}" size="text-2xl" separator class="mb-6" />

        @if (session('status'))
            <x-alert class="alert-success mb-6" icon="o-check-circle" dismissible>
                {{ session('status') }}
            </x-alert>
        @endif

        @include('livewire.database-server._form', [
            'form' => $form,
            'submitLabel' => 'Create Database Server',
            'isEdit' => false,
        ])
    </div>
</div>
