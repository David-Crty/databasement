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
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="w-56">{{ __('Environment Variable') }}</th>
                            <th class="w-64">{{ __('Value') }}</th>
                            <th>{{ __('Description') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($appConfig as $key => $config)
                            <tr>
                                <td class="font-mono text-sm">{{ $config['env'] }}</td>
                                <td class="font-mono text-sm text-base-content/80">{{ $config['value'] ?: '-' }}</td>
                                <td class="text-sm text-base-content/70">{{ $config['description'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <!-- Backup Configuration -->
        <x-card title="{{ __('Backup') }}" subtitle="{{ __('Backup and restore operation settings.') }}" shadow>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="w-56">{{ __('Environment Variable') }}</th>
                            <th class="w-64">{{ __('Value') }}</th>
                            <th>{{ __('Description') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($backupConfig as $key => $config)
                            <tr>
                                <td class="font-mono text-sm">{{ $config['env'] }}</td>
                                <td class="font-mono text-sm text-base-content/80">{{ $config['value'] ?: '-' }}</td>
                                <td class="text-sm text-base-content/70">{{ $config['description'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <!-- Notification Configuration -->
        <x-card title="{{ __('Notifications') }}" subtitle="{{ __('Failure notification settings for backup and restore jobs.') }}" shadow>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="w-56">{{ __('Environment Variable') }}</th>
                            <th class="w-64">{{ __('Value') }}</th>
                            <th>{{ __('Description') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($notificationConfig as $key => $config)
                            <tr>
                                <td class="font-mono text-sm">{{ $config['env'] }}</td>
                                <td class="font-mono text-sm text-base-content/80">{{ $config['value'] ?: '-' }}</td>
                                <td class="text-sm text-base-content/70">{{ $config['description'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
</div>
