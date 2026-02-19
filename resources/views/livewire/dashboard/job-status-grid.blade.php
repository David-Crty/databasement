<x-card>
    <div class="flex items-center justify-between mb-3">
        <h3 class="font-semibold text-sm">{{ __('Job Status') }}</h3>
        <div class="flex items-center gap-3 text-xs text-base-content/70">
            <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-success inline-block"></span> {{ __('Completed') }}</span>
            <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-error inline-block"></span> {{ __('Failed') }}</span>
            <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-warning inline-block"></span> {{ __('Running') }}</span>
            <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-info inline-block"></span> {{ __('Pending') }}</span>
        </div>
    </div>

    @if($jobs->isEmpty())
        <div class="text-center py-4 text-base-content/50 text-sm">
            {{ __('No jobs yet.') }}
        </div>
    @else
        <div
            x-data="{ show: false, server: '', database: '', status: '', time: '', duration: '', x: 0, y: 0 }"
            @mouseleave="show = false"
            class="relative"
        >
            <div class="grid gap-1" style="grid-template-columns: repeat(auto-fill, 14px)">
                @foreach($jobs as $job)
                    @php
                        $colorClass = match($job->status) {
                            'completed' => 'bg-success',
                            'failed' => 'bg-error',
                            'running' => 'bg-warning',
                            default => 'bg-info',
                        };

                        $serverName = $job->snapshot?->databaseServer?->name
                            ?? $job->restore?->targetServer?->name
                            ?? __('Unknown');

                        $databaseName = $job->snapshot?->database_name
                            ?? $job->restore?->snapshot?->database_name
                            ?? '';
                    @endphp
                    <button
                        wire:click="viewLogs('{{ $job->id }}')"
                        data-server="{{ $serverName }}"
                        data-database="{{ $databaseName }}"
                        data-status="{{ ucfirst($job->status) }}"
                        data-time="{{ $job->created_at?->format('M d, H:i') ?? '' }}"
                        data-duration="{{ $job->getHumanDuration() ?? '' }}"
                        @mouseenter="
                            server = $el.dataset.server;
                            database = $el.dataset.database;
                            status = $el.dataset.status;
                            time = $el.dataset.time;
                            duration = $el.dataset.duration;
                            let rect = $el.getBoundingClientRect();
                            let container = $el.closest('.relative').getBoundingClientRect();
                            x = rect.left - container.left + rect.width / 2;
                            y = rect.top - container.top;
                            show = true;
                        "
                        @mouseleave="show = false"
                        class="w-3.5 h-3.5 rounded-sm cursor-pointer transition-opacity hover:opacity-75 {{ $colorClass }}"
                    ></button>
                @endforeach
            </div>

            {{-- Shared popover --}}
            <div
                x-show="show"
                x-cloak
                x-transition.opacity.duration.150ms
                class="absolute z-10 pointer-events-none bg-base-300 text-base-content text-xs rounded-lg px-3 py-2 shadow-lg whitespace-nowrap -translate-x-1/2 -translate-y-full"
                :style="`left: ${x}px; top: ${y - 6}px;`"
            >
                <div class="font-semibold" x-text="database ? server + ' / ' + database : server"></div>
                <div class="text-base-content/70">
                    <span x-text="status"></span>
                    <span x-show="time"> &mdash; <span x-text="time"></span></span>
                    <span x-show="duration"> (<span x-text="duration"></span>)</span>
                </div>
            </div>
        </div>
    @endif

    @include('livewire.backup-job._logs-modal')
</x-card>
