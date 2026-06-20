<x-modal wire:model="showTokenModal" :title="__('Agent Token')" class="backdrop-blur" persistent box-class="max-w-2xl">

    <x-alert icon="o-exclamation-triangle" class="alert-warning mb-5">
        <div>
            <p class="font-semibold">{{ __('Save this token — it will not be shown again.') }}</p>
            <p class="text-sm opacity-80 mt-0.5">
                {{ __('Store it somewhere safe before closing this dialog.') }}
            </p>
        </div>
    </x-alert>

    <x-tabs selected="docker-tab" label-class="tabs-sm">



        {{-- Docker tab --}}
        <x-tab name="docker-tab" :label="__('Docker')" icon="devicon.docker">
            <x-copy-input
                :value="$dockerCommand"
                :label="__('Run the agent as a Docker container')"
                multiline
                :rows="6"
            />
        </x-tab>

        {{-- Environment Variables tab --}}
        <x-tab name="env-tab" :label="__('Environment Variables')" icon="o-command-line">
            <x-copy-input
                :value="$envVars"
                :label="__('Set these in your agent environment')"
                multiline
                :rows="4"
            />
        </x-tab>

        {{-- Token tab --}}
        <x-tab name="token-tab" :label="__('Token')" icon="o-key">
            <x-copy-input :value="$newToken" :label="__('Agent Token')" />
        </x-tab>

    </x-tabs>

    <x-slot:actions>
        <x-button :label="__('Done')" class="btn-primary" wire:click="closeTokenModal" />
    </x-slot:actions>

</x-modal>
