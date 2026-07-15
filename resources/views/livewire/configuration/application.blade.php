<div>
    <x-header :title="__('Configuration')" separator>
        <x-slot:subtitle>
            @if ($this->canManage)
                {{ __('Manage application settings.') }}
            @else
                {{ __('View application settings. Only administrators can modify these settings.') }}
            @endif
        </x-slot:subtitle>
    </x-header>

    @include('livewire.configuration._tabs', ['active' => 'application'])

    <x-card shadow class="min-w-0">
        <x-card-heading :title="__('Application')" :subtitle="__('Environment variables controlling application behavior.')">
            <x-button
                :label="__('Documentation')"
                icon="o-book-open"
                link="https://david-crty.github.io/databasement/self-hosting/configuration/application"
                external
                class="btn-ghost btn-sm"
            />
        </x-card-heading>
        @include('livewire.configuration._config-table', ['rows' => $appConfig])

        <form wire:submit="saveApplicationConfig" class="mt-4 border-t border-base-200/60 pt-4">
            <div class="divide-y divide-base-200/80">
                <x-config-row :label="__('Database Browser')" :description="__('Enable the built-in Adminer database browser for viewing and managing database contents. Not available for servers connected through SSH tunnels or remote agents.')">
                    <x-toggle wire:model.live="form.adminer_enabled" :disabled="!$this->canManage" />
                </x-config-row>

                @if ($form->adminer_enabled)
                    <div class="space-y-3 py-4">
                        <x-alert class="alert-info" icon="o-information-circle">
                            {{ __('Access to the database browser is controlled by the "Use Adminer" ability on each role. Manage it under Roles.') }}
                        </x-alert>

                        <x-alert class="alert-warning" icon="o-exclamation-triangle">
                            {{ __('Users will have the same permissions as the database connection user configured on each server. Ensure connection users have appropriate privilege levels.') }}
                        </x-alert>
                    </div>
                @endif
            </div>

            @if ($this->canManage)
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
