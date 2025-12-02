<x-modal wire:model="showLogsModal" title="{{ __('Job Logs') }}" class="backdrop-blur" box-class="w-11/12 max-w-6xl max-h-[90vh]">
    @if($this->selectedJob)
        <div class="space-y-4">
            <!-- Job Info Header -->
            <div class="p-4 bg-base-200 rounded-lg space-y-2">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm text-base-content/70">
                            {{ $this->selectedJob->snapshot ? 'Backup' : 'Restore' }} Job
                        </div>
                        <div class="font-semibold">
                            @if($this->selectedJob->snapshot)
                                {{ $this->selectedJob->snapshot->databaseServer->name }} / {{ $this->selectedJob->snapshot->database_name }}
                            @elseif($this->selectedJob->restore)
                                {{ $this->selectedJob->restore->targetServer->name }} / {{ $this->selectedJob->restore->schema_name }}
                            @endif
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm text-base-content/70">{{ __('Status') }}</div>
                        @if($this->selectedJob->status === 'completed')
                            <x-badge value="{{ __('Completed') }}" class="badge-success" />
                        @elseif($this->selectedJob->status === 'failed')
                            <x-badge value="{{ __('Failed') }}" class="badge-error" />
                        @elseif($this->selectedJob->status === 'running')
                            <x-badge value="{{ __('Running') }}" class="badge-warning" />
                        @else
                            <x-badge value="{{ ucfirst($this->selectedJob->status) }}" class="badge-info" />
                        @endif
                    </div>
                </div>
                @if($this->selectedJob->started_at)
                    <div class="text-sm text-base-content/70">
                        Started: {{ $this->selectedJob->started_at->format('Y-m-d H:i:s') }}
                        @if($this->selectedJob->completed_at)
                            | Completed: {{ $this->selectedJob->completed_at->format('Y-m-d H:i:s') }}
                            | Duration: {{ $this->selectedJob->getHumanDuration() }}
                        @endif
                    </div>
                @endif
            </div>

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
                                $isError = ($log['type'] === 'command' && isset($log['exit_code']) && $log['exit_code'] !== 0) ||
                                           ($log['type'] !== 'command' && ($log['level'] ?? '') === 'error');
                                $isWarning = $log['type'] !== 'command' && ($log['level'] ?? '') === 'warning';
                                $isSuccess = ($log['type'] === 'command' && isset($log['exit_code']) && $log['exit_code'] === 0) ||
                                             ($log['type'] !== 'command' && ($log['level'] ?? '') === 'success');
                                $timestamp = \Carbon\Carbon::parse($log['timestamp']);
                                $logLevel = $log['type'] === 'command' ? 'command' : ($log['level'] ?? 'info');
                                $hasDetails = $log['type'] === 'command' || (isset($log['context']) && !empty($log['context']));
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
                                                            <div class="flex items-center">
                                                                <x-heroicon-c-window
                                                                    class="w-4 h-4 mr-1 "/>
                                                                <code class="text-primary">{{ Str::limit($log['command'], 80) }}</code>
                                                            </div>
                                                        @else
                                                            {{ $log['message'] }}
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
                                            <div class="ml-44 pl-4 pr-4 pb-4 space-y-3">
                                                @if($log['type'] === 'command')
                                                    <!-- Full Command -->
                                                    <div>
                                                        <div class="text-xs text-base-content/50 mb-1">{{ __('Full Command') }}</div>
                                                        <div class="bg-base-300 p-3 rounded font-mono text-sm overflow-x-auto">
                                                            <code class="text-success">$ {{ $log['command'] }}</code>
                                                        </div>
                                                    </div>

                                                    @if(isset($log['output']) && !empty(trim($log['output'])))
                                                        <div>
                                                            <div class="text-xs text-base-content/50 mb-1">{{ __('Output') }}</div>
                                                            <div class="bg-base-300 p-3 rounded font-mono text-xs overflow-x-auto max-h-48 overflow-y-auto">
                                                                <pre class="text-base-content/80 whitespace-pre-wrap">{{ trim($log['output']) }}</pre>
                                                            </div>
                                                        </div>
                                                    @else
                                                        <div class="text-xs text-base-content/50 mb-1">{{ __('No output') }}</div>
                                                    @endif

                                                    @if(isset($log['exit_code']) || isset($log['duration_ms']))
                                                        <div class="flex items-center gap-2">
                                                            @if(isset($log['exit_code']))
                                                                <span class="text-xs text-base-content/50">{{ __('Exit code') }}:</span>
                                                                <x-badge
                                                                    :value="$log['exit_code']"
                                                                    :class="$log['exit_code'] === 0 ? 'badge-success badge-sm' : 'badge-error badge-sm'"
                                                                />
                                                            @endif
                                                            @if(isset($log['duration_ms']))
                                                                <span class="text-xs text-base-content/50">{{ __('Duration') }}:</span>
                                                                <span class="text-xs text-base-content/80">{{ $log['duration_ms'] }}ms</span>
                                                            @endif
                                                        </div>
                                                    @endif
                                                @else
                                                    <div>
                                                        <div class="text-xs text-base-content/50 mb-1">{{ __('Context') }}</div>
                                                        <div class="bg-base-300 p-3 rounded font-mono text-xs overflow-x-auto">
                                                            <pre class="text-base-content/80">{{ json_encode($log['context'], JSON_PRETTY_PRINT) }}</pre>
                                                        </div>
                                                    </div>
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
