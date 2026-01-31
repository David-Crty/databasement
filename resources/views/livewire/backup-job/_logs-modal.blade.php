<x-modal wire:model="showLogsModal" title="{{ __('Job Logs') }}" class="backdrop-blur" box-class="w-full sm:w-11/12 max-w-6xl max-h-[90vh]">
    @if($this->selectedJob)
        <div class="space-y-4" x-data="{ showMetadata: false }">
            <!-- Job Info Header -->
            @php
                $triggeredBy = $this->selectedJob->snapshot?->triggeredBy ?? $this->selectedJob->restore?->triggeredBy;
                $snapshot = $this->selectedJob->snapshot ?? $this->selectedJob->restore?->snapshot;
            @endphp
            <div class="p-4 bg-base-200 rounded-lg space-y-2">
                {{-- Mobile: stacked layout, Desktop: horizontal --}}
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    {{-- Left: Icon + Job info --}}
                    <div class="flex items-center gap-3">
                        @if($this->selectedJob->snapshot)
                            <x-database-type-icon :type="$this->selectedJob->snapshot->database_type" class="w-6 h-6 shrink-0" />
                        @elseif($this->selectedJob->restore?->snapshot)
                            <x-database-type-icon :type="$this->selectedJob->restore->snapshot->database_type" class="w-6 h-6 shrink-0" />
                        @endif
                        <div class="min-w-0">
                            <div class="text-sm text-base-content/70">
                                {{ $this->selectedJob->snapshot ? __('Backup') : __('Restore') }}
                            </div>
                            <div class="font-semibold truncate">
                                @if($this->selectedJob->snapshot)
                                    {{ $this->selectedJob->snapshot->databaseServer->name }} / {{ $this->selectedJob->snapshot->database_name }}
                                @elseif($this->selectedJob->restore)
                                    {{ $this->selectedJob->restore->targetServer->name }} / {{ $this->selectedJob->restore->schema_name }}
                                @endif
                            </div>
                        </div>
                    </div>
                    {{-- Right: Status + Metadata button --}}
                    @php
                        $jobStatus = $this->selectedJob->status;
                        $jobStatusBadge = match($jobStatus) {
                            'completed' => ['label' => __('Completed'), 'class' => 'badge-success'],
                            'failed' => ['label' => __('Failed'), 'class' => 'badge-error'],
                            'running' => ['label' => __('Running'), 'class' => 'badge-warning'],
                            default => ['label' => ucfirst($jobStatus), 'class' => 'badge-info'],
                        };
                    @endphp
                    <div class="flex items-center gap-2 sm:gap-4">
                        @if($jobStatus === 'running')
                            <div class="badge {{ $jobStatusBadge['class'] }} gap-1">
                                <x-loading class="loading-spinner loading-xs" />
                                {{ $jobStatusBadge['label'] }}
                            </div>
                        @else
                            <x-badge value="{{ $jobStatusBadge['label'] }}" class="{{ $jobStatusBadge['class'] }}" />
                        @endif
                        @if($snapshot?->metadata)
                            <x-button
                                label="{{ __('Metadata') }}"
                                icon="o-document-text"
                                class="btn-ghost btn-sm"
                                x-on:click="showMetadata = !showMetadata"
                                ::class="showMetadata && 'btn-active'"
                            />
                        @endif
                    </div>
                </div>

                {{-- Compression and Volume badges --}}
                @if($snapshot)
                    <div class="flex flex-wrap items-center gap-2 pt-1">
                        {{-- Compression Type --}}
                        @if($snapshot->compression_type)
                            <div class="badge badge-outline gap-1.5">
                                <x-icon :name="$snapshot->compression_type->icon()" class="w-3.5 h-3.5" />
                                {{ $snapshot->compression_type->label() }}
                            </div>
                        @endif

                        {{-- Volume Type --}}
                        @if($snapshot->volume)
                            <div class="badge badge-outline gap-1.5">
                                <x-volume-type-icon :type="$snapshot->volume->type" class="w-3.5 h-3.5" />
                                {{ $snapshot->volume->getVolumeType()?->label() ?? $snapshot->volume->type }}
                            </div>
                        @endif
                    </div>
                @endif
                <div class="text-xs sm:text-sm text-base-content/70">
                    @if($this->selectedJob->started_at)
                        {{ __('Started') }}: {{ \App\Support\Formatters::humanDate($this->selectedJob->started_at) }}
                        @if($this->selectedJob->completed_at)
                            <span class="hidden sm:inline">|</span>
                            <br class="sm:hidden">
                            {{ __('Duration') }}: {{ $this->selectedJob->getHumanDuration() }}
                        @endif
                        <span class="hidden sm:inline">|</span>
                        <br class="sm:hidden">
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
                    <!-- Table Header (hidden on mobile) -->
                    <div class="hidden sm:flex bg-base-200 text-sm font-semibold border-b border-base-300">
                        <div class="w-36 flex-shrink-0 px-4 py-3">{{ __('Date') }}</div>
                        <div class="w-24 flex-shrink-0 py-3 text-center">{{ __('Type') }}</div>
                        <div class="flex-1 px-4 py-3">{{ __('Message') }}</div>
                    </div>

                    <!-- Logs Container -->
                    <div class="max-h-[60vh] overflow-y-auto divide-y divide-base-300">
                        @foreach($logs as $index => $log)
                            @php
                                $timestamp = \Carbon\Carbon::parse($log['timestamp']);
                                $isCommand = $log['type'] === 'command';
                                $isRunning = $isCommand && ($log['status'] ?? null) === 'running';

                                // Determine visual state via pattern matching
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

                                // Badge styling based on log level (used in multiple places)
                                $badgeClass = match($logLevel) {
                                    'error' => 'badge-error badge-sm',
                                    'warning' => 'badge-warning badge-sm',
                                    'success' => 'badge-success badge-sm',
                                    'command' => 'badge-neutral badge-sm',
                                    default => 'badge-info badge-sm',
                                };

                                // Row border color based on state
                                $borderClass = match(true) {
                                    $isError => 'border-l-error bg-error/5',
                                    $isWarning => 'border-l-warning',
                                    $isSuccess => 'border-l-success',
                                    default => 'border-l-info',
                                };
                            @endphp


                            <div class="flex border-l-4 {{ $borderClass }}">
                                <x-collapse
                                    :collapse-plus-minus="$hasDetails"
                                    :no-icon="!$hasDetails"
                                    class="flex-1 rounded-none border-none {{ !$hasDetails ? '[&_.collapse-content]:!p-0 pointer-events-none' : '' }}"
                                >
                                    <x-slot:heading>
                                        {{-- Mobile: timestamp+badge / message, Desktop: timestamp | badge | message --}}
                                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center w-full -ml-4 {{ !$hasDetails ? 'pointer-events-auto cursor-default' : '' }}">
                                            {{-- Timestamp --}}
                                            <div class="sm:w-36 flex-shrink-0 px-4 flex items-center gap-2">
                                                <span class="font-mono text-xs sm:text-sm text-base-content/70 whitespace-nowrap">
                                                    {{ $timestamp->format('H:i:s') }}
                                                    <span class="sm:hidden text-base-content/50">· {{ $timestamp->format('M d') }}</span>
                                                    <span class="hidden sm:inline">{{ $timestamp->format(' M d') }}</span>
                                                </span>
                                                {{-- Badge on mobile only --}}
                                                <span class="sm:hidden">
                                                    <x-badge :value="ucfirst($logLevel)" class="{{ $badgeClass }}" />
                                                </span>
                                            </div>
                                            {{-- Badge (desktop only, fixed width for alignment) --}}
                                            <div class="hidden sm:block w-24 flex-shrink-0 ml-2">
                                                <span class="inline-flex w-full">
                                                    <x-badge :value="ucfirst($logLevel)" class="{{ $badgeClass }}" />
                                                    @if($isRunning)
                                                        <x-loading class="loading-spinner loading-xs text-warning ml-1" />
                                                    @endif
                                                </span>
                                            </div>
                                            {{-- Message --}}
                                            <div class="flex-1 px-4 pr-2 sm:pr-4 min-w-0 overflow-hidden">
                                                @if($isCommand)
                                                    <code class="bg-neutral text-neutral-content px-2 py-1 rounded text-xs font-mono truncate block">
                                                        <span class="text-success">$</span> {{ $log['command'] }}
                                                    </code>
                                                @else
                                                    <span class="text-sm truncate block {{ $isError ? 'text-error' : '' }}">
                                                        {{ $log['message'] }}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </x-slot:heading>

                                    <x-slot:content>
                                        @if($hasDetails)
                                            <div class="py-3 space-y-3">
                                                @if($isCommand)
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
                                        @endif
                                    </x-slot:content>
                                </x-collapse>
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
