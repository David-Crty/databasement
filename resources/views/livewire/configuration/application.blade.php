<div>
    <x-header :title="__('Configuration')" separator>
        <x-slot:subtitle>
            @if ($this->isAdmin)
                {{ __('Manage application settings.') }}
            @else
                {{ __('View application settings. Only administrators can modify these settings.') }}
            @endif
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

        <form wire:submit="saveApplicationConfig" class="mt-4 border-t border-base-200/60 pt-4">
            <div class="divide-y divide-base-200/80">
                <x-config-row :label="__('Database Browser')" :badge="__('New')" badge-classes="badge-primary badge-soft badge-xs" :description="__('Enable the built-in Adminer database browser for viewing and managing database contents. Not available for servers connected through SSH tunnels or remote agents.')">
                    <x-toggle wire:model.live="form.adminer_enabled" :disabled="!$this->isAdmin" />
                </x-config-row>

                @if ($form->adminer_enabled)
                    <x-config-row :label="__('Database Browser Role')" :description="__('Minimum role required to access the database browser.')">
                        <x-select wire:model="form.adminer_role" :options="$adminerRoleOptions" :disabled="!$this->isAdmin" />
                    </x-config-row>

                    <x-alert class="alert-warning" icon="o-exclamation-triangle">
                        {{ __('Users will have the same permissions as the database connection user configured on each server. Ensure connection users have appropriate privilege levels.') }}
                    </x-alert>
                @endif
            </div>

            @if ($this->isAdmin)
            <div class="flex items-center justify-end border-t border-base-200/60 pt-6">
                <x-button
                    type="submit"
                    class="btn-primary"
                    :label="__('Save Application Settings')"
                    spinner="saveApplicationConfig"
                />
            </div>
            @endif
        </form>
    </x-card>
</div>
