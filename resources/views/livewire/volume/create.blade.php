<div>
    <div class="mx-auto max-w-4xl">
        <flux:heading size="xl" class="mb-2">{{ __('Create Volume') }}</flux:heading>
        <flux:subheading class="mb-6">{{ __('Add a new storage volume for backups') }}</flux:subheading>

        @if (session('status'))
            <x-banner variant="success" dismissible class="mb-6">
                {{ session('status') }}
            </x-banner>
        @endif

        <x-card class="space-y-6">
            @include('livewire.volume._form', [
                'form' => $form,
                'submitLabel' => 'Create Volume',
            ])
        </x-card>
    </div>
</div>
