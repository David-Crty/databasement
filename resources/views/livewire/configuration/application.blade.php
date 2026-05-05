<div>
    <x-header :title="__('Configuration')" separator>
        <x-slot:subtitle>
            {{ __('General application settings (read-only).') }}
        </x-slot:subtitle>
    </x-header>

    @include('livewire.configuration._tabs', ['active' => 'application'])

    <x-card :title="__('Application')" :subtitle="__('Environment variables controlling application behavior.')" shadow class="min-w-0">
        <x-slot:menu>
            <x-button
                :label="__('Documentation')"
                icon="o-book-open"
                link="https://david-crty.github.io/databasement/self-hosting/configuration/application"
                external
                class="btn-ghost btn-sm"
            />
        </x-slot:menu>
        @include('livewire.configuration._config-table', ['rows' => $appConfig])
    </x-card>
</div>
