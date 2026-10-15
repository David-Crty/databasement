<div>
    <x-modal wire:model="showModal" :title="__('Backup selected databases')" :subtitle="$serverName" box-class="max-w-2xl overflow-visible" separator>
        @if(! $loaded)
            <div class="flex items-center justify-center gap-2 py-8 text-base-content/70">
                <x-loading class="loading-spinner loading-sm" />
                <span>{{ __('Listing databases…') }}</span>
            </div>
        @else
            <div class="space-y-6">
                <p class="text-sm text-base-content/70">
                    {{ __('Pick the databases to back up now. The backup configurations are not changed, so scheduled backups keep their full scope.') }}
                </p>

                @foreach($configurations as $backupId => $configuration)
                    <div wire:key="backup-databases-{{ $backupId }}">
                        @if($configuration['problem'])
                            <div class="text-sm font-semibold mb-2">{{ $configuration['label'] }}</div>
                            <x-alert :title="match ($configuration['problem']) {
                                         'unlisted' => __('The databases could not be listed: :message', ['message' => $configuration['detail']]),
                                         'no-history' => __('No database has been backed up with this configuration yet. Run Backup now once so the agent lists them.'),
                                         default => __('No database matches this backup configuration.'),
                                     }"
                                     class="alert-warning alert-soft text-sm" icon="o-exclamation-triangle" />
                        @else
                            <x-choices-offline
                                wire:model="selections.{{ $backupId }}"
                                :label="$configuration['label']"
                                :options="$configuration['options']"
                                :hint="$configuration['source'] === \App\Livewire\DatabaseServer\BackupDatabasesModal::SOURCE_HISTORY
                                    ? __('This server is reached through an agent, so the list shows the databases this configuration has backed up before.')
                                    : null"
                                :no-result-text="__('No results found.')"
                                searchable
                                clearable
                            />
                        @endif
                    </div>
                @endforeach

                @error('selections')
                    <div class="text-sm text-error">{{ $message }}</div>
                @enderror
            </div>
        @endif

        <x-slot:actions>
            <x-button :label="__('Cancel')" @click="$wire.showModal = false" />
            <x-button :label="__('Backup now')" icon="bi.database-fill-up" class="btn-primary"
                      wire:click="backup" spinner :disabled="! $loaded" />
        </x-slot:actions>
    </x-modal>
</div>
