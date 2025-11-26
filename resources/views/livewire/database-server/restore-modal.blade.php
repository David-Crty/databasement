<div>
    <x-modal wire:model="showModal" title="{{ __('Restore Database Snapshot') }}" box-class="max-w-3xl w-11/12 space-y-6">
        <p class="text-sm opacity-70">
            {{ __('Restore to:') }} <strong>{{ $targetServer?->name }}</strong>
        </p>

        <!-- Step Indicator -->
        <div class="mt-6 mb-8">
            <ul class="steps steps-horizontal w-full">
                <li class="step {{ $currentStep >= 1 ? 'step-primary' : '' }}">{{ __('Select Source') }}</li>
                <li class="step {{ $currentStep >= 2 ? 'step-primary' : '' }}">{{ __('Select Snapshot') }}</li>
                <li class="step {{ $currentStep >= 3 ? 'step-primary' : '' }}">{{ __('Destination') }}</li>
            </ul>
        </div>

        <!-- Step 1: Select Source Server -->
        @if($currentStep === 1)
            <div class="space-y-4">
                <p class="text-sm opacity-70">
                    {{ __('Select a source database server to restore from. Only servers with the same database type (:type) are shown.', ['type' => $targetServer?->database_type]) }}
                </p>

                @if($this->compatibleServers->isEmpty())
                    <div class="p-4 text-center border rounded-lg border-base-300">
                        <p class="opacity-70">{{ __('No compatible database servers with snapshots found.') }}</p>
                    </div>
                @else
                    <div class="space-y-2 max-h-96 overflow-y-auto">
                        @foreach($this->compatibleServers as $server)
                            <div
                                wire:click="selectSourceServer('{{ $server->id }}')"
                                class="p-4 border rounded-lg cursor-pointer hover:bg-base-200 border-base-300 {{ $selectedSourceServerId === $server->id ? 'border-primary bg-primary/10' : '' }}"
                            >
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="font-semibold">{{ $server->name }}</div>
                                        <div class="text-sm opacity-70">
                                            {{ $server->host }}:{{ $server->port }}
                                            @if($server->database_name)
                                                &bull; {{ $server->database_name }}
                                            @endif
                                        </div>
                                        @if($server->description)
                                            <div class="text-sm opacity-50 mt-1">{{ $server->description }}</div>
                                        @endif
                                    </div>
                                    <div class="ml-4 text-sm opacity-50">
                                        {{ $server->snapshots->count() }} {{ Str::plural('snapshot', $server->snapshots->count()) }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        <!-- Step 2: Select Snapshot -->
        @if($currentStep === 2)
            <div class="space-y-4">
                <p class="text-sm opacity-70">
                    {{ __('Select a snapshot to restore from') }} <strong>{{ $this->selectedSourceServer?->name }}</strong>.
                </p>

                @if($this->selectedSourceServer?->snapshots->isEmpty())
                    <div class="p-4 text-center border rounded-lg border-base-300">
                        <p class="opacity-70">{{ __('No completed snapshots found.') }}</p>
                    </div>
                @else
                    <div class="space-y-2 max-h-96 overflow-y-auto">
                        @foreach($this->selectedSourceServer->snapshots as $snapshot)
                            <div
                                wire:click="selectSnapshot('{{ $snapshot->id }}')"
                                class="p-4 border rounded-lg cursor-pointer hover:bg-base-200 border-base-300 {{ $selectedSnapshotId === $snapshot->id ? 'border-primary bg-primary/10' : '' }}"
                            >
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="font-semibold">{{ $snapshot->database_name }}</div>
                                        <div class="text-sm opacity-70">
                                            {{ __('Created:') }} {{ $snapshot->created_at->format('Y-m-d H:i:s') }} ({{ $snapshot->created_at->diffForHumans() }})
                                        </div>
                                        <div class="text-sm opacity-50 mt-1">
                                            {{ __('Size:') }} {{ $snapshot->getHumanFileSize() }}
                                            @if($snapshot->getDurationMs())
                                                &bull; {{ __('Duration:') }} {{ $snapshot->getHumanDuration() }}
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="flex justify-start mt-4">
                    <x-button class="btn-ghost" wire:click="previousStep">
                        {{ __('Back') }}
                    </x-button>
                </div>
            </div>
        @endif

        <!-- Step 3: Enter Destination Schema -->
        @if($currentStep === 3)
            <div class="space-y-4">
                <x-input
                    wire:model.live="schemaName"
                    label="{{ __('Destination Database Name') }}"
                    placeholder="{{ __('Enter database name') }}"
                    list="existing-databases"
                />

                @if(!empty($existingDatabases))
                    <datalist id="existing-databases">
                        @foreach($existingDatabases as $db)
                            <option value="{{ $db }}">
                        @endforeach
                    </datalist>
                @endif

                <x-alert class="alert-warning" icon="o-exclamation-triangle">
                    {{ __('If the database already exists, it will be deleted and replaced with the snapshot data.') }}
                </x-alert>

                @if($this->selectedSnapshot)
                    <div class="p-4 border rounded-lg bg-base-200 border-base-300">
                        <div class="text-sm font-semibold mb-2">{{ __('Restore Summary') }}</div>
                        <div class="text-sm opacity-70 space-y-1">
                            <div><strong>{{ __('Source:') }}</strong> {{ $this->selectedSourceServer?->name }} &bull; {{ $this->selectedSnapshot->database_name }}</div>
                            <div><strong>{{ __('Snapshot:') }}</strong> {{ $this->selectedSnapshot->created_at->format('Y-m-d H:i:s') }}</div>
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

        <!-- Initial step buttons -->
        @if($currentStep === 1)
            <div class="flex gap-2 mt-6">
                <div class="flex-1"></div>
                <x-button class="btn-ghost" @click="$wire.showModal = false">
                    {{ __('Cancel') }}
                </x-button>
            </div>
        @endif
    </x-modal>
</div>
