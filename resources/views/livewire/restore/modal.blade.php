@php use App\Enums\RestoreModalMode; @endphp
<div>
    <x-modal wire:model="showModal" box-class="max-w-3xl w-11/12" class="backdrop-blur">
        <x-header :title="__('Restore Database Snapshot')" icon="bi.database-fill-down"
                  icon-classes="text-success w-6 h-6" size="text-xl" class="!mb-5"/>
        <div class="space-y-4">
            {{-- Locked context badges --}}
            @if($mode->targetServerLocked() && $targetServer)
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs opacity-60">{{ __('Restoring to:') }}</span>
                    <x-badge class="badge-primary gap-1.5 h-auto py-1 whitespace-normal text-left">
                        <x-icon :name="$targetServer->database_type->icon()" class="w-3.5 h-3.5 shrink-0"/>
                        <span class="min-w-0 break-all">{{ $targetServer->name }}</span>
                    </x-badge>
                </div>
            @endif

            @if($mode->snapshotLocked() && $this->selectedSnapshot)
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs opacity-60">{{ __('Snapshot:') }}</span>
                    <x-badge class="badge-secondary gap-1.5 h-auto py-1 whitespace-normal text-left">
                        <x-icon :name="$this->selectedSnapshot->database_type->icon()" class="w-3.5 h-3.5 shrink-0"/>
                        <span class="min-w-0 break-all">{{ $this->selectedSnapshot->databaseServer->name }} · {{ $this->selectedSnapshot->database_name }}</span>
                    </x-badge>
                </div>
            @endif

            {{-- Step indicator --}}
            <ul class="steps steps-horizontal w-full">
                @foreach($this->stepLabels() as $i => $label)
                    <li class="step {{ $currentStep >= ($i + 1) ? 'step-primary' : '' }}">{{ $label }}</li>
                @endforeach
            </ul>

            {{-- Step body: snapshot picker --}}
            @if(
                ($mode === RestoreModalMode::FromServer && $currentStep === 1) ||
                ($mode === RestoreModalMode::FromRestoreIndex && $currentStep === 1)
            )
                <div class="space-y-4">
                    <p class="text-sm opacity-70">
                        @if($mode === RestoreModalMode::FromServer)
                            {{ __('Select a snapshot to restore. Only snapshots from :type servers are shown.', ['type' => $targetServer?->database_type?->label()]) }}
                        @else
                            {{ __('Select a snapshot to restore.') }}
                        @endif
                    </p>

                    <div class="flex flex-wrap items-center gap-4">
                        @if($mode === RestoreModalMode::FromRestoreIndex)
                            <x-select
                                wire:model.live="dbTypeFilter"
                                :options="collect($this->dbTypeOptions())->prepend(['id' => '', 'name' => __('All types')])->all()"
                                class="w-44"
                            />
                        @endif

                        <x-select
                            wire:model.live="serverFilter"
                            :options="$this->compatibleServers->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->prepend(['id' => '', 'name' => __('All servers')])->all()"
                            class="w-48"
                        />
                        <x-input
                            wire:model.live.debounce.300ms="snapshotSearch"
                            :placeholder="__('Search database...')"
                            icon="o-magnifying-glass"
                            clearable
                            class="flex-1"
                        />
                    </div>

                    <x-hr class="my-2"/>

                    <div wire:loading.class="opacity-60 pointer-events-none" class="transition-opacity duration-200">
                        @if(!$this->paginatedSnapshots || $this->paginatedSnapshots->isEmpty())
                            <div class="p-4 text-center border rounded-lg border-base-300">
                                <p class="opacity-70">
                                    @if($snapshotSearch || $serverFilter || $dbTypeFilter)
                                        {{ __('No snapshots found matching your filters.') }}
                                    @else
                                        {{ __('No compatible snapshots found.') }}
                                    @endif
                                </p>
                            </div>
                        @else
                            <div class="space-y-1 max-h-80 overflow-y-auto">
                                @foreach($this->paginatedSnapshots as $snapshot)
                                    <div
                                        wire:click="selectSnapshot('{{ $snapshot->id }}')"
                                        class="px-3 py-2 border rounded-lg cursor-pointer hover:bg-base-200 border-base-300 {{ $selectedSnapshotId === $snapshot->id ? 'border-primary bg-primary/10' : '' }}"
                                    >
                                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 sm:gap-4">
                                            <div class="flex-1 min-w-0 space-y-0.5">
                                                <div class="text-sm">
                                                    <span class="opacity-50">{{ __('Database:') }}</span>
                                                    <span class="font-medium">{{ $snapshot->database_name }}</span>
                                                </div>
                                                <div class="text-xs flex items-center gap-1.5">
                                                    <span class="opacity-50">{{ __('Server:') }}</span>
                                                    <x-icon :name="$snapshot->database_type->icon()" class="w-3.5 h-3.5"/>
                                                    <span
                                                        class="opacity-70">{{ $snapshot->databaseServer->name }}</span>
                                                </div>
                                            </div>
                                            <div class="sm:text-right space-y-0.5">
                                                <div
                                                    class="text-xs opacity-60 flex flex-wrap items-center gap-x-2 sm:justify-end sm:flex-nowrap sm:whitespace-nowrap">
                                                    <x-loading wire:loading
                                                               wire:target="selectSnapshot('{{ $snapshot->id }}')"
                                                               class="loading-xs"/>
                                                    <span>{{ \App\Support\Formatters::humanDate($snapshot->created_at) }}</span>
                                                    <span class="opacity-50">({{ $snapshot->created_at->diffForHumans() }})</span>
                                                    <span class="opacity-50">&bull;</span>
                                                    <span>{{ $snapshot->getHumanFileSize() }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            @if($this->paginatedSnapshots->hasPages())
                                <div class="pt-2">
                                    {{ $this->paginatedSnapshots->links() }}
                                </div>
                            @endif
                        @endif
                    </div>

                    <div class="flex gap-2 mt-6">
                        <div class="flex-1"></div>
                        <x-button class="btn-ghost" @click="$wire.showModal = false">
                            {{ __('Cancel') }}
                        </x-button>
                    </div>
                </div>
            @endif

            {{-- Step body: target server picker --}}
            @if(
                ($mode === RestoreModalMode::FromSnapshot && $currentStep === 1) ||
                ($mode === RestoreModalMode::FromRestoreIndex && $currentStep === 2)
            )
                <div class="space-y-4">
                    <p class="text-sm opacity-70">
                        {{ __('Select a target server to restore to. Only :type servers are shown.', ['type' => $this->selectedSnapshot?->database_type?->label()]) }}
                    </p>

                    @if($this->compatibleTargetServers->isEmpty())
                        <div class="p-4 text-center border rounded-lg border-base-300">
                            <p class="opacity-70">{{ __('No compatible target servers found.') }}</p>
                        </div>
                    @else
                        <div class="space-y-1 max-h-80 overflow-y-auto">
                            @foreach($this->compatibleTargetServers as $server)
                                <div
                                    wire:click="selectTargetServer('{{ $server->id }}')"
                                    class="px-3 py-2 border rounded-lg cursor-pointer hover:bg-base-200 border-base-300 {{ $targetServer?->id === $server->id ? 'border-primary bg-primary/10' : '' }}"
                                >
                                    <div class="flex items-center justify-between gap-4">
                                        <div class="flex-1 min-w-0">
                                            <div class="text-sm font-medium">{{ $server->name }}</div>
                                            <div class="text-xs opacity-60">{{ $server->host }}@if($server->port)
                                                    :{{ $server->port }}
                                                @endif</div>
                                        </div>
                                        <x-loading wire:loading wire:target="selectTargetServer('{{ $server->id }}')"
                                                   class="loading-xs"/>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="flex gap-2 mt-6">
                        @if($mode === RestoreModalMode::FromRestoreIndex)
                            <x-button class="btn-ghost" wire:click="previousStep">{{ __('Back') }}</x-button>
                        @endif
                        <div class="flex-1"></div>
                        <x-button class="btn-ghost" @click="$wire.showModal = false">{{ __('Cancel') }}</x-button>
                    </div>
                </div>
            @endif

            {{-- Step body: configure (final step) --}}
            @if($this->isConfigureStep() && $currentStep > 1)
                <div class="space-y-4">
                    <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                        <x-input
                            wire:model.live.debounce.200ms="schemaName"
                            :label="__('Destination Database Name')"
                            :placeholder="__('Type or select database name...')"
                            @focus="open = true"
                            @keydown.escape="open = false"
                            autocomplete="off"
                        />
                        @error('schemaName')
                        <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror

                        @if(count($this->filteredDatabases) > 0)
                            <div
                                x-show="open"
                                x-transition:enter="transition ease-out duration-100"
                                x-transition:enter-start="opacity-0 scale-95"
                                x-transition:enter-end="opacity-100 scale-100"
                                x-transition:leave="transition ease-in duration-75"
                                x-transition:leave-start="opacity-100 scale-100"
                                x-transition:leave-end="opacity-0 scale-95"
                                class="absolute z-50 w-full mt-1 bg-base-100 border border-base-300 rounded-lg shadow-lg max-h-48 overflow-y-auto"
                            >
                                @foreach($this->filteredDatabases as $database)
                                    <div
                                        wire:click="selectDatabase('{{ $database }}')"
                                        @click="open = false"
                                        class="px-3 py-2 cursor-pointer hover:bg-base-200 text-sm {{ $schemaName === $database ? 'bg-primary/10 font-medium' : '' }}"
                                    >
                                        {{ $database }}
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @if(in_array($schemaName, $existingDatabases))
                        <x-alert class="alert-warning" icon="o-exclamation-triangle">
                            {{ __('The database') }}
                            <x-badge class="badge-error badge-dash" :value="$schemaName"/> {{ __('already exists.') }}
                            <br>
                            {{ __('It will be overwritten if you continue.') }}
                        </x-alert>
                    @endif

                    @if($targetServer?->database_type === \App\Enums\DatabaseType::POSTGRESQL)
                        <x-input
                            wire:model="ownerUser"
                            :label="__('Transfer database ownership to user after restore')"
                            :placeholder="__('PostgreSQL username (leave empty to skip)')"
                            :hint="__('Transfers ownership of the database and all its objects (tables, sequences, functions, schemas) to this user. Useful when the restore user differs from the application user.')"
                        />
                    @endif

                    @if(in_array($targetServer?->database_type, [\App\Enums\DatabaseType::MYSQL, \App\Enums\DatabaseType::POSTGRESQL]))
                        <x-checkbox
                            wire:model="forceDatabase"
                            :label="__('Drop and recreate database before restore')"
                            :hint="__('Not usually needed — dumps already include per-table DROP/CREATE statements. Use this only if you need a completely clean database (e.g. to remove tables not in the snapshot).')"
                        />
                    @endif

                    @if($this->selectedSnapshot)
                        <div class="p-4 border rounded-lg bg-base-200 border-base-300">
                            <div class="text-sm font-semibold mb-2">{{ __('Restore Summary') }}</div>
                            <div class="text-sm opacity-70 space-y-1">
                                <div><strong>{{ __('Source:') }}</strong>
                                    {{ $this->selectedSnapshot->databaseServer->name }} &bull; {{ $this->selectedSnapshot->database_name }}
                                </div>
                                <div>
                                    <strong>{{ __('Snapshot:') }}</strong> {{ \App\Support\Formatters::humanDate($this->selectedSnapshot->created_at) }}
                                </div>
                                <div><strong>{{ __('Target:') }}</strong>
                                    {{ $targetServer?->name }} &bull; {{ $schemaName ?: __('(enter name)') }}</div>
                                <div>
                                    <strong>{{ __('Size:') }}</strong> {{ $this->selectedSnapshot->getHumanFileSize() }}
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="flex gap-2 mt-6">
                        <x-button class="btn-ghost" wire:click="previousStep">{{ __('Back') }}</x-button>
                        <div class="flex-1"></div>
                        <x-button class="btn-ghost" @click="$wire.showModal = false">{{ __('Cancel') }}</x-button>
                        <x-button class="btn-primary" wire:click="restore" spinner="restore">
                            {{ __('Restore Database') }}
                        </x-button>
                    </div>
                </div>
            @endif
        </div>
    </x-modal>
</div>
