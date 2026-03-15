<div>
    <x-header title="{{ __('Edit Volume') }}" subtitle="{{ __('Update storage volume configuration') }}" size="text-2xl" separator class="mb-6" />

    @if (session('status'))
        <x-alert class="alert-success mb-6" icon="o-check-circle" dismissible>
            {{ session('status') }}
        </x-alert>
    @endif

    @if($hasSnapshots)
        <x-alert class="alert-warning mb-6" icon="o-lock-closed">
            {{ __('This volume has existing snapshots. Only the name can be modified. Configuration changes are locked to protect backup integrity.') }}
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
