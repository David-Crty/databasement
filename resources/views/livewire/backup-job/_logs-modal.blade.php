<x-modal wire:model="showLogsModal" title="{{ __('Job Logs') }}" class="backdrop-blur" box-class="w-11/12 max-w-6xl max-h-[90vh]">
    @if($this->selectedJob)
        <div class="space-y-4" x-data="{ showMetadata: false }">
            <!-- Job Info Header -->
            @php
                $triggeredBy = $this->selectedJob->snapshot?->triggeredBy ?? $this->selectedJob->restore?->triggeredBy;
                $snapshot = $this->selectedJob->snapshot ?? $this->selectedJob->restore?->snapshot;
            @endphp
            <div class="p-4 bg-base-200 rounded-lg space-y-2">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        @if($this->selectedJob->snapshot)
                            <x-database-type-icon :type="$this->selectedJob->snapshot->database_type" class="w-6 h-6" />
                        @elseif($this->selectedJob->restore?->snapshot)
                            <x-database-type-icon :type="$this->selectedJob->restore->snapshot->database_type" class="w-6 h-6" />
                        @endif
                        <div>
                            <div class="text-sm text-base-content/70">
                                {{ $this->selectedJob->snapshot ? __('Backup') : __('Restore') }}
                            </div>
                            <div class="font-semibold">
                                @if($this->selectedJob->snapshot)
                                    {{ $this->selectedJob->snapshot->databaseServer->name }} / {{ $this->selectedJob->snapshot->database_name }}
                                @elseif($this->selectedJob->restore)
                                    {{ $this->selectedJob->restore->targetServer->name }} / {{ $this->selectedJob->restore->schema_name }}
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        @if($snapshot?->metadata)
                            <x-button
                                label="{{ __('Metadata') }}"
                                icon="o-document-text"
                                class="btn-ghost btn-sm"
                                x-on:click="showMetadata = !showMetadata"
                                ::class="showMetadata && 'btn-active'"
                            />
                        @endif
                        <div class="text-right">
                            @if($this->selectedJob->status === 'completed')
                                <x-badge value="{{ __('Completed') }}" class="badge-success" />
                            @elseif($this->selectedJob->status === 'failed')
                                <x-badge value="{{ __('Failed') }}" class="badge-error" />
                            @elseif($this->selectedJob->status === 'running')
                                <div class="badge badge-warning gap-1">
                                    <x-loading class="loading-spinner loading-xs" />
                                    {{ __('Running') }}
                                </div>
                            @else
                                <x-badge value="{{ ucfirst($this->selectedJob->status) }}" class="badge-info" />
                            @endif
                        </div>
                    </div>
                </div>
                <div class="text-sm text-base-content/70">
                    @if($this->selectedJob->started_at)
                        {{ __('Started') }}: {{ \App\Support\Formatters::humanDate($this->selectedJob->started_at) }}
                        @if($this->selectedJob->completed_at)
                            | {{ __('Duration') }}: {{ $this->selectedJob->getHumanDuration() }}
                        @endif
                        |
                    @endif
                    @if($triggeredBy)
                        {{ __('By') }}: {{ $triggeredBy->name }}
                    @endif
                </div>
            </div>

            <!-- Metadata Panel -->
            @if($snapshot?->metadata)
                <div x-show="showMetadata" x-collapse>
                    <div class="p-4 bg-base-300 rounded-lg">
                        <pre class="text-xs font-mono whitespace-pre-wrap overflow-x-auto">{{ json_encode($snapshot->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                </div>
            @endif

            <!-- Logs Table -->
            @php
                $logs = $this->selectedJob->getLogs();
            @endphp

            @if(count($logs) > 0)
                <div class="border border-base-300 rounded-lg overflow-hidden">
                    <!-- Table Header -->
                    <div class="flex bg-base-200 text-sm font-semibold border-b border-base-300">
                        <div class="w-48 flex-shrink-0 px-4 py-3">{{ __('Date') }}</div>
                        <div class="flex-1 px-4 py-3">{{ __('Message') }}</div>
                    </div>

                    <!-- Logs Container -->
                    <div class="max-h-[60vh] overflow-y-auto divide-y divide-base-300">
                        @foreach($logs as $index => $log)
                            @php
                                $timestamp = \Carbon\Carbon::parse($log['timestamp']);
                                $isCommand = $log['type'] === 'command';
                                $isRunning = $isCommand && ($log['status'] ?? null) === 'running';

                                // Determine visual state via pattern matching instead of complex booleans
                                $rowState = match(true) {
                                    $isRunning => 'warning',
                                    $isCommand && isset($log['exit_code']) && $log['exit_code'] !== 0 => 'error',
                                    $isCommand && isset($log['exit_code']) && $log['exit_code'] === 0 => 'success',
                                    !$isCommand => $log['level'] ?? 'info',
                                    default => 'info',
                                };

                                $isError = $rowState === 'error';
                                $isWarning = $rowState === 'warning';
                                $isSuccess = $rowState === 'success';

                                $logLevel = $isCommand ? 'command' : ($log['level'] ?? 'info');
                                $hasDetails = $isCommand || !empty($log['context']);
                            @endphp


                            <div class="flex border-l-4 {{ $isError ? 'border-l-error bg-error/5' : ($isWarning ? 'border-l-warning' : ($isSuccess ? 'border-l-success' : 'border-l-info')) }}">
                                @if($hasDetails)
                                    <x-collapse collapse-plus-minus class="flex-1 rounded-none border-none">
                                        <x-slot:heading>
                                            <div class="flex items-center w-full -ml-4">
                                                <div class="w-44 flex-shrink-0 px-4 font-mono text-sm text-base-content/70">
                                                    {{ $timestamp->format('M d, H:i:s') }}
                                                </div>
                                                <div class="flex-1 px-4 flex items-center gap-3">
                                                    <span class="text-sm truncate {{ $isError ? 'text-error' : '' }}">
                                                        @if($log['type'] === 'command')
                                                            <div class="flex items-center gap-2">
                                                                <code class="bg-neutral text-neutral-content px-2 py-1 rounded text-xs font-mono truncate max-w-xl">
                                                                    <span class="text-success">$</span> {{ Str::limit($log['command'], 80) }}
                                                                </code>
                                                                <x-badge value="{{ __('Command') }}" class="badge-neutral badge-sm" />
                                                                @if($isRunning)
                                                                    <x-loading class="loading-spinner loading-xs text-warning" />
                                                                @endif
                                                            </div>
                                                        @else
                                                            {{ Str::limit($log['message'], 80) }}
                                                        @endif
                                                    </span>
                                                    @if($log['type'] !== 'command')
                                                        <x-badge
                                                            :value="ucfirst($logLevel)"
                                                            :class="match($logLevel) {
                                                                'error' => 'badge-error badge-sm',
                                                                'warning' => 'badge-warning badge-sm',
                                                                'success' => 'badge-success badge-sm',
                                                                default => 'badge-info badge-sm'
                                                            }"
                                                        />
                                                    @endif
                                                </div>
                                            </div>
                                        </x-slot:heading>

                                        <x-slot:content>
                                            <div class="pl-4 pr-4 pb-4 space-y-3">
                                                @if($log['type'] === 'command')
                                                    <!-- Command & Output -->
                                                    <div class="mockup-code text-sm max-h-64 overflow-auto">
                                                        <pre data-prefix="$"><code>{{ $log['command'] }}</code></pre>
                                                        @if(isset($log['output']) && !empty(trim($log['output'])))
                                                            @foreach(explode("\n", trim($log['output'])) as $line)
                                                                <pre data-prefix=">"><code class="{{ $isError ? 'text-error' : '' }}">{{ $line }}</code></pre>
                                                            @endforeach
                                                        @endif
                                                    </div>

                                                    @if($isRunning || isset($log['exit_code']) || isset($log['duration_ms']))
                                                        <div class="flex items-center gap-2">
                                                            @if($isRunning)
                                                                <div class="badge badge-warning badge-sm gap-1">
                                                                    <x-loading class="loading-spinner loading-xs" />
                                                                    {{ __('Running') }}
                                                                </div>
                                                            @elseif(isset($log['exit_code']))
                                                                <span class="text-xs text-base-content/50">{{ __('Exit code') }}:</span>
                                                                <x-badge
                                                                    :value="$log['exit_code']"
                                                                    :class="$log['exit_code'] === 0 ? 'badge-success badge-sm' : 'badge-error badge-sm'"
                                                                />
                                                            @endif
                                                            @if(isset($log['duration_ms']))
                                                                <span class="text-xs text-base-content/50">{{ __('Duration') }}:</span>
                                                                <span class="text-xs text-base-content/80">{{ \App\Support\Formatters::humanDuration($log['duration_ms']) }}</span>
                                                            @endif
                                                        </div>
                                                    @endif
                                                @else
                                                    <!-- Full Message -->
                                                    <div>
                                                        <div class="text-xs text-base-content/50 mb-1">{{ __('Message') }}</div>
                                                        <div class="bg-base-300 p-3 rounded text-sm overflow-x-auto {{ $isError ? 'text-error' : '' }}">
                                                            {{ $log['message'] }}
                                                        </div>
                                                    </div>
                                                    @if(isset($log['context']) && !empty($log['context']))
                                                        <div>
                                                            <div class="text-xs text-base-content/50 mb-1">{{ __('Context') }}</div>
                                                            <div class="bg-base-300 p-3 rounded font-mono text-xs overflow-x-auto">
                                                                <pre class="text-base-content/80">{{ json_encode($log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                            </div>
                                                        </div>
                                                    @endif
                                                @endif
                                            </div>
                                        </x-slot:content>
                                    </x-collapse>
                                @else
                                    <!-- No details - plain row without collapse -->
                                    <div class="flex-1 flex items-center py-4 px-4 font-semibold">
                                        <div class="w-44 flex-shrink-0 font-mono text-sm text-base-content/70">
                                            {{ $timestamp->format('M d, H:i:s') }}
                                        </div>
                                        <div class="flex-1 flex items-center gap-3">
                                            <span class="text-sm {{ $isError ? 'text-error' : '' }}">
                                                {{ $log['message'] }}
                                            </span>
                                            <x-badge
                                                :value="ucfirst($logLevel)"
                                                :class="match($logLevel) {
                                                    'error' => 'badge-error badge-sm',
                                                    'warning' => 'badge-warning badge-sm',
                                                    'success' => 'badge-success badge-sm',
                                                    default => 'badge-info badge-sm'
                                                }"
                                            />
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="text-center py-8 text-base-content/50">
                    <x-icon name="o-document-text" class="w-12 h-12 mx-auto mb-2 opacity-50" />
                    <div>{{ __('No logs available for this job.') }}</div>
                </div>
            @endif
        </div>

        <x-slot:actions>
            <x-button label="{{ __('Close') }}" @click="$wire.showLogsModal = false" />
        </x-slot:actions>
    @endif
</x-modal>
