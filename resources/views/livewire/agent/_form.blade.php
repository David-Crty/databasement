@props(['form', 'submitLabel' => __('Save'), 'cancelRoute' => 'agents.index'])

<x-form wire:submit="save" class="space-y-6">
    <div class="space-y-4">
        <x-input
            wire:model="form.name"
            :label="__('Agent Name')"
            :placeholder="__('e.g., Production Network Agent')"
            :hint="__('A friendly name to identify this agent')"
            type="text"
            required
        />

        <x-toggle
            wire:model="form.transfer_via_server"
            :label="__('Transfer files through this server')"
            :hint="__('The agent sends and receives backup files over its connection to this server, which reads and writes the storage volumes. The agent then needs no access to the volumes, and can also use local volumes.')"
        />
    </div>

    <!-- Form Actions -->
    <div class="flex flex-col-reverse sm:flex-row items-center justify-end gap-3 pt-4">
        <x-button class="btn-ghost w-full sm:w-auto" :link="route($cancelRoute)" wire:navigate>
            {{ __('Cancel') }}
        </x-button>
        <x-button
            class="btn-primary w-full sm:w-auto"
            type="submit"
            icon="o-check"
            spinner="save"
        >
            {{ $submitLabel }}
        </x-button>
    </div>
</x-form>
