<div>
    <x-header title="{{ __('Configuration') }}" separator>
        <x-slot:subtitle>
            {{ __('Read-only view of the application configuration.') }}
        </x-slot:subtitle>
    </x-header>

    <div class="grid gap-6">
        <x-alert class="alert-info" icon="o-information-circle">
            {{ __('View the full list of environment variables') }}
            <x-slot:actions>
                <x-button
                    label="{{ __('Documentation') }}"
                    icon="o-book-open"
                    link="https://david-crty.github.io/databasement/self-hosting/configuration"
                    external
                    class="btn-ghost"
                />
            </x-slot:actions>
        </x-alert>

        <!-- Application Configuration -->
        <x-card title="{{ __('Application') }}" subtitle="{{ __('General application settings.') }}" shadow>
            <x-slot:menu>
                <x-button
                    label="{{ __('Documentation') }}"
                    icon="o-book-open"
                    link="https://david-crty.github.io/databasement/self-hosting/configuration/application"
                    external
                    class="btn-ghost btn-sm"
                />
            </x-slot:menu>
            @include('livewire.configuration._config-table', ['rows' => $appConfig])
        </x-card>

        <!-- Backup Configuration -->
        <x-card title="{{ __('Backup') }}" subtitle="{{ __('Backup and restore operation settings.') }}" shadow>
            <x-slot:menu>
                <x-button
                    label="{{ __('Documentation') }}"
                    icon="o-book-open"
                    link="https://david-crty.github.io/databasement/self-hosting/configuration/backup"
                    external
                    class="btn-ghost btn-sm"
                />
            </x-slot:menu>
            @include('livewire.configuration._config-table', ['rows' => $backupConfig])
        </x-card>

        <!-- Notification Configuration -->
        <x-card title="{{ __('Notifications') }}" subtitle="{{ __('Failure notification settings for backup and restore jobs.') }}" shadow>
            <x-slot:menu>
                <x-button
                    label="{{ __('Documentation') }}"
                    icon="o-book-open"
                    link="https://david-crty.github.io/databasement/self-hosting/configuration/notification"
                    external
                    class="btn-ghost btn-sm"
                />
            </x-slot:menu>
            @include('livewire.configuration._config-table', ['rows' => $notificationConfig])
        </x-card>

        <!-- SSO Configuration -->
        <x-card title="{{ __('SSO') }}" subtitle="{{ __('OAuth and Single Sign-On authentication settings.') }}" shadow>
            <x-slot:menu>
                <x-button
                    label="{{ __('Documentation') }}"
                    icon="o-book-open"
                    link="https://david-crty.github.io/databasement/self-hosting/configuration/sso"
                    external
                    class="btn-ghost btn-sm"
                />
            </x-slot:menu>
            @include('livewire.configuration._config-table', ['rows' => $ssoConfig])
        </x-card>
    </div>
</div>
