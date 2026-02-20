<div class="h-full">
    <x-card shadow class="h-full flex flex-col">
        <x-slot:title>
            <div class="flex items-center justify-between w-full">
                <span>{{ __('Latest Jobs') }}</span>
                <x-select
                    wire:model.live="statusFilter"
                    :options="$statusOptions"
                    class="select-sm w-32"
                />
            </div>
        </x-slot:title>

        @if($jobs->isEmpty())
            <div class="text-center text-base-content/50 py-8">
                @if($statusFilter !== 'all')
                    {{ __('No jobs with this status.') }}
                @else
                    {{ __('No jobs yet.') }}
                @endif
            </div>
        @else
            <div class="space-y-2">
                @foreach($jobs as $job)
                    <div class="py-2 border-b border-base-200 last:border-0">
                        {{-- Mobile: 2-line layout, Desktop: 1-line layout --}}
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3">
                            {{-- Line 1: Type + Server + Status --}}
                            <div class="flex items-center gap-2 sm:gap-3 sm:flex-1 min-w-0">
                                {{-- Type Badge --}}
                                @if($job->snapshot)
                                    <x-badge value="{{ __('Backup') }}" class="badge-primary badge-sm shrink-0" />
                                @elseif($job->restore)
                                    <x-badge value="{{ __('Restore') }}" class="badge-secondary badge-sm shrink-0" />
                                @endif

                                {{-- Server Name (icon visible on desktop only in line 1) --}}
                                <div class="flex items-center gap-2 flex-1 min-w-0">
                                    @if($job->snapshot && $job->snapshot->databaseServer)
                                        <x-database-type-icon :type="$job->snapshot->database_type" class="w-4 h-4 shrink-0 hidden sm:block" />
                                        <span class="truncate text-sm font-medium">{{ $job->snapshot->databaseServer->name }}</span>
                                        <span class="text-xs text-base-content/50 truncate hidden sm:inline">{{ $job->snapshot->database_name }}</span>
                                    @elseif($job->restore && $job->restore->targetServer)
                                        @if($job->restore->snapshot)
                                            <x-database-type-icon :type="$job->restore->snapshot->database_type" class="w-4 h-4 shrink-0 hidden sm:block" />
                                        @endif
                                        <span class="truncate text-sm font-medium">{{ $job->restore->targetServer->name }}</span>
                                        <span class="text-xs text-base-content/50 truncate hidden sm:inline">{{ $job->restore->schema_name }}</span>
                                    @endif
                                </div>

                                {{-- Status --}}
                                <div class="shrink-0">
                                    @if($job->status === 'completed')
                                        <x-badge value="{{ __('Done') }}" class="badge-success badge-sm" />
                                    @elseif($job->status === 'failed')
                                        <x-badge value="{{ __('Failed') }}" class="badge-error badge-sm" />
                                    @elseif($job->status === 'running')
                                        <div class="badge badge-warning badge-sm gap-1">
                                            <x-loading class="loading-spinner loading-xs" />
                                            {{ __('Running') }}
                                        </div>
                                    @else
                                        <x-badge value="{{ __('Pending') }}" class="badge-info badge-sm" />
                                    @endif
                                </div>
                            </div>

                            {{-- Line 2 on mobile: DB icon + name + time + logs --}}
                            <div class="flex items-center gap-2 sm:hidden text-base-content/70">
                                @if($job->snapshot && $job->snapshot->databaseServer)
                                    <x-database-type-icon :type="$job->snapshot->database_type" class="w-4 h-4 shrink-0" />
                                    <span class="text-xs truncate flex-1">{{ $job->snapshot->database_name }}</span>
                                @elseif($job->restore && $job->restore->targetServer)
                                    @if($job->restore->snapshot)
                                        <x-database-type-icon :type="$job->restore->snapshot->database_type" class="w-4 h-4 shrink-0" />
                                    @endif
                                    <span class="text-xs truncate flex-1">{{ $job->restore->schema_name }}</span>
                                @endif
                                <span class="text-xs text-base-content/50 shrink-0">{{ $job->created_at->diffForHumans(short: true) }}</span>
                                <x-button
                                    icon="o-document-text"
                                    wire:click="viewLogs('{{ $job->id }}')"
                                    tooltip="{{ __('View Logs') }}"
                                    class="btn-ghost btn-xs shrink-0"
                                />
                            </div>

                            {{-- Time & Logs (desktop only) --}}
                            <div class="hidden sm:flex items-center gap-2 shrink-0">
                                <div class="text-xs text-base-content/50 w-16 text-right">
                                    {{ $job->created_at->diffForHumans(short: true) }}
                                </div>
                                <x-button
                                    icon="o-document-text"
                                    wire:click="viewLogs('{{ $job->id }}')"
                                    tooltip="{{ __('View Logs') }}"
                                    class="btn-ghost btn-xs"
                                />
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- View All Link --}}
            <div class="mt-4 pt-3 border-t border-base-200">
                <a href="{{ route('jobs.index') }}" wire:navigate class="text-sm text-primary hover:underline flex items-center gap-1">
                    {{ __('View all jobs') }}
                    <x-icon name="o-arrow-right" class="w-4 h-4" />
                </a>
            </div>
        @endif
    </x-card>

    {{-- Logs Modal --}}
    @include('livewire.backup-job._logs-modal')
</div>
