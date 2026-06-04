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

            {{-- Step indicator (hidden when the flow has a single step) --}}
            @if(count($this->stepLabels()) > 1)
                <ul class="steps steps-horizontal w-full">
                    @foreach($this->stepLabels() as $i => $label)
                        <li class="step {{ $currentStep >= ($i + 1) ? 'step-primary' : '' }}">{{ $label }}</li>
                    @endforeach
                </ul>
            @endif

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

            {{-- Step body: target + destination (final step) --}}
            @if($this->isConfigureStep())
                <div class="space-y-4">
                    @include('livewire.restore._destination-step', [
                        'targetLocked' => $mode->targetServerLocked(),
                        'snapshot' => $this->selectedSnapshot,
                    ])

                    <div class="flex gap-2 mt-6">
                        @if($currentStep > 1)
                            <x-button class="btn-ghost" wire:click="previousStep">{{ __('Back') }}</x-button>
                        @endif
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
