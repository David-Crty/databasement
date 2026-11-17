<div>
    <x-header title="{{ __('Edit Volume') }}" subtitle="{{ __('Update storage volume configuration') }}" size="text-2xl" separator class="mb-6">
        <x-slot:actions>
            <x-button :label="__('Back')" link="{{ route('volumes.index') }}" wire:navigate icon="o-arrow-left" class="btn-ghost" />
        </x-slot:actions>
    </x-header>


    @if($hasSnapshots)
        <x-alert class="alert-warning mb-6" icon="o-lock-closed">
            {{ __('This volume has existing snapshots, so its storage location is locked. You can still change the name, the storage limit and the credentials. New credentials are saved only if they pass a connection test.') }}
        </x-alert>
    @endif

    <x-card class="space-y-6">
        @include('livewire.volume._form', [
            'form' => $form,
            'submitLabel' => 'Update Volume',
            'readonly' => $hasSnapshots,
        ])
    </x-card>
</div>
