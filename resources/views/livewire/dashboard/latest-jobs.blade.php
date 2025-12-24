<div wire:init="load" wire:poll.5s="fetchJobs" class="h-full">
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

        @if(!$loaded)
            <div class="space-y-3">
                @for($i = 0; $i < 5; $i++)
                    <div class="flex items-center gap-3 animate-pulse">
                        <div class="w-16 h-5 bg-base-300 rounded"></div>
                        <div class="flex-1 h-5 bg-base-300 rounded"></div>
                        <div class="w-20 h-5 bg-base-300 rounded"></div>
                    </div>
                @endfor
            </div>
        @elseif($jobs->isEmpty())
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
                    <div class="flex items-center gap-3 py-2 border-b border-base-200 last:border-0">
                        {{-- Type Badge --}}
                        @if($job->snapshot)
                            <x-badge value="{{ __('Backup') }}" class="badge-primary badge-sm" />
                        @elseif($job->restore)
                            <x-badge value="{{ __('Restore') }}" class="badge-secondary badge-sm" />
                        @endif

                        {{-- Server/Database Info --}}
                        <div class="flex-1 min-w-0">
                            @if($job->snapshot && $job->snapshot->databaseServer)
                                <div class="flex items-center gap-2">
                                    <x-database-type-icon :type="$job->snapshot->database_type" class="w-4 h-4" />
                                    <span class="truncate text-sm font-medium">{{ $job->snapshot->databaseServer->name }}</span>
                                    <span class="text-xs text-base-content/50 truncate">{{ $job->snapshot->database_name }}</span>
                                </div>
                            @elseif($job->restore && $job->restore->targetServer)
                                <div class="flex items-center gap-2">
                                    @if($job->restore->snapshot)
                                        <x-database-type-icon :type="$job->restore->snapshot->database_type" class="w-4 h-4" />
                                    @endif
                                    <span class="truncate text-sm font-medium">{{ $job->restore->targetServer->name }}</span>
                                    <span class="text-xs text-base-content/50 truncate">{{ $job->restore->schema_name }}</span>
                                </div>
                            @endif
                        </div>

                        {{-- Status --}}
                        <div class="flex items-center gap-2">
                            @if($job->status === 'completed')
                                <x-badge value="{{ __('Done') }}" class="badge-success badge-sm" />
                            @elseif($job->status === 'failed')
                                <x-badge value="{{ __('Failed') }}" class="badge-error badge-sm" />
                            @elseif($job->status === 'running')
                                <div class="badge badge-warning badge-sm">
                                    <x-loading class="loading-xs mr-1" />
                                    {{ __('Running') }}
                                </div>
                            @else
                                <x-badge value="{{ __('Pending') }}" class="badge-info badge-sm" />
                            @endif
                        </div>

                        {{-- Time --}}
                        <div class="text-xs text-base-content/50 w-20 text-right shrink-0">
                            {{ $job->created_at->diffForHumans(short: true) }}
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
</div>
