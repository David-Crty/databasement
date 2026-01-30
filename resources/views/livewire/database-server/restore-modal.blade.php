<div>
    <x-modal wire:model="showModal" title="{{ __('Restore Database Snapshot') }}" subtitle="{{ __('Restore to:') }} {{ $targetServer?->name }}" box-class="max-w-3xl w-11/12" class="backdrop-blur">
        <div class="space-y-4">
            <!-- Step Indicator -->
            <ul class="steps steps-horizontal w-full">
                <li class="step {{ $currentStep >= 1 ? 'step-primary' : '' }}">{{ __('Select Snapshot') }}</li>
                <li class="step {{ $currentStep >= 2 ? 'step-primary' : '' }}">{{ __('Destination') }}</li>
            </ul>

        <!-- Step 1: Select Snapshot -->
        @if($currentStep === 1)
            <div class="space-y-4">
                <div class="flex items-center justify-between gap-4">
                    <p class="text-sm opacity-70">
                        {{ __('Select a snapshot to restore. Only snapshots from :type servers are shown.', ['type' => $targetServer?->database_type?->label()]) }}
                    </p>
                </div>

                <div class="flex items-center gap-4">
                    <x-select
                        wire:model.live="serverFilter"
                        :options="$this->compatibleServers->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->prepend(['id' => '', 'name' => __('All servers')])->all()"
                        class="w-48"
                    />
                    <x-input
                        wire:model.live.debounce.300ms="snapshotSearch"
                        placeholder="{{ __('Search database...') }}"
                        icon="o-magnifying-glass"
                        clearable
                        class="flex-1"
                    />
                </div>

                <x-hr class="my-2" />

                <div wire:loading.class="opacity-60 pointer-events-none" class="transition-opacity duration-200">
                    @if(!$this->paginatedSnapshots || $this->paginatedSnapshots->isEmpty())
                        <div class="p-4 text-center border rounded-lg border-base-300">
                            <p class="opacity-70">
                                @if($snapshotSearch || $serverFilter)
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
                                <div class="flex items-center justify-between gap-4">
                                    <div class="flex-1 min-w-0 space-y-0.5">
                                        <div class="text-sm">
                                            <span class="opacity-50">{{ __('Database:') }}</span>
                                            <span class="font-medium">{{ $snapshot->database_name }}</span>
                                        </div>
                                        <div class="text-xs">
                                            <span class="opacity-50">{{ __('Server:') }}</span>
                                            <span class="opacity-70">{{ $snapshot->databaseServer?->name }}</span>
                                        </div>
                                    </div>
                                    <div class="text-right space-y-0.5">
                                        <div class="text-xs opacity-60 whitespace-nowrap flex items-center justify-end gap-2">
                                            <x-loading wire:loading wire:target="selectSnapshot('{{ $snapshot->id }}')" class="loading-xs" />
                                            {{ \App\Support\Formatters::humanDate($snapshot->created_at) }}
                                            <span class="opacity-50">({{ $snapshot->created_at->diffForHumans() }})</span>
                                            &bull;
                                            {{ $snapshot->getHumanFileSize() }}
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

        <!-- Step 2: Enter Destination Schema -->
        @if($currentStep === 2)
            <div class="space-y-4">
                <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                    <x-input
                        wire:model.live.debounce.200ms="schemaName"
                        label="{{ __('Destination Database Name') }}"
                        placeholder="{{ __('Type or select database name...') }}"
                        @focus="open = true"
                        @keydown.escape="open = false"
                        autocomplete="off"
                    />
                    @error('schemaName')
                        <p class="text-error text-sm mt-1">{{ $message }}</p>
                    @enderror

                    <!-- Dropdown suggestions -->
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
                        The database <x-badge class="badge-error badge-dash" :value="$schemaName" /> already exists.<br>
                        {{ __('It will be overwritten if you continue.') }}
                    </x-alert>
                @endif

                @if($this->selectedSnapshot)
                    <div class="p-4 border rounded-lg bg-base-200 border-base-300">
                        <div class="text-sm font-semibold mb-2">{{ __('Restore Summary') }}</div>
                        <div class="text-sm opacity-70 space-y-1">
                            <div><strong>{{ __('Source:') }}</strong> {{ $this->selectedSnapshot->databaseServer?->name }} &bull; {{ $this->selectedSnapshot->database_name }}</div>
                            <div><strong>{{ __('Snapshot:') }}</strong> {{ \App\Support\Formatters::humanDate($this->selectedSnapshot->created_at) }}</div>
                            <div><strong>{{ __('Target:') }}</strong> {{ $targetServer?->name }} &bull; {{ $schemaName ?: __('(enter name)') }}</div>
                            <div><strong>{{ __('Size:') }}</strong> {{ $this->selectedSnapshot->getHumanFileSize() }}</div>
                        </div>
                    </div>
                @endif

                <div class="flex gap-2 mt-6">
                    <x-button class="btn-ghost" wire:click="previousStep">
                        {{ __('Back') }}
                    </x-button>
                    <div class="flex-1"></div>
                    <x-button class="btn-ghost" @click="$wire.showModal = false">
                        {{ __('Cancel') }}
                    </x-button>
                    <x-button class="btn-primary" wire:click="restore" spinner="restore">
                        {{ __('Restore Database') }}
                    </x-button>
                </div>
            </div>
        @endif
        </div>
    </x-modal>
</div>
