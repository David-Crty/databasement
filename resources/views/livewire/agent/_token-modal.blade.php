<x-modal wire:model="showTokenModal" :title="__('Agent Token')" class="backdrop-blur" persistent box-class="max-w-2xl">

    <x-alert icon="o-exclamation-triangle" class="alert-warning mb-5">
        <div>
            <p class="font-semibold">{{ __('Save this token — it will not be shown again.') }}</p>
            <p class="text-sm opacity-80 mt-0.5">
                {{ __('Store it somewhere safe before closing this dialog.') }}
            </p>
        </div>
    </x-alert>

    <x-copy-input
        :value="$envVars"
        :label="__('Set these in your agent environment')"
        multiline
        :rows="2"
    />

    <p class="mt-6 mb-1 text-sm font-semibold">{{ __('Deploy the agent next to your database') }}</p>

    <x-tabs wire:model="tokenModalTab" label-class="tabs-sm">

        <x-tab name="docker-compose-tab" :label="__('Docker Compose')" icon="devicon.docker">
            <x-copy-input
                :value="$dockerComposeConfig"
                :label="__('Add this service to your docker-compose.yml, then run docker compose up -d')"
                multiline
                :rows="8"
            />
        </x-tab>

        <x-tab name="helm-tab" :label="__('Helm / Kubernetes')" icon="devicon.kubernetes">
            <x-copy-input
                :value="$helmCommand"
                :label="__('Install the chart with only this agent')"
                multiline
                :rows="8"
            />
        </x-tab>

        <x-tab name="docker-tab" :label="__('Docker')" icon="devicon.docker">
            <x-copy-input
                :value="$dockerCommand"
                :label="__('Run the agent as a Docker container')"
                multiline
                :rows="5"
            />
        </x-tab>

    </x-tabs>

    <p class="mt-2 text-sm">
        <a href="https://david-crty.github.io/databasement/user-guide/agents" target="_blank" rel="noopener" class="link link-primary">
            {{ __('Read the agent documentation') }}
        </a>
    </p>

    <x-slot:actions>
        <x-button :label="__('Done')" class="btn-primary" wire:click="closeTokenModal" spinner />
    </x-slot:actions>

</x-modal>
