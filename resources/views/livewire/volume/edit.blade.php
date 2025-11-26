<div>
    <div class="mx-auto max-w-4xl">
        <x-header title="{{ __('Edit Volume') }}" subtitle="{{ __('Update storage volume configuration') }}" size="text-2xl" separator class="mb-6" />

        @if (session('status'))
            <x-alert class="alert-success mb-6" icon="o-check-circle" dismissible>
                {{ session('status') }}
            </x-alert>
        @endif

        <x-card class="space-y-6">
            @include('livewire.volume._form', [
                'form' => $form,
                'submitLabel' => 'Update Volume',
            ])
        </x-card>
    </div>
</div>
