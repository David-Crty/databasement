<x-card title="{{ __('Jobs Status') }}" subtitle="{{ __(':count jobs in the last 30 days', ['count' => $this->jobs->count()]) }}">
    @if($this->jobs->isEmpty())
        <div class="text-center py-4 text-base-content/50 text-sm">
            {{ __('No jobs yet.') }}
        </div>
    @else
        <div @mouseleave="$dispatch('hide-job-tooltip')">
            <div class="grid gap-1 max-h-48 overflow-y-auto" style="grid-template-columns: repeat(auto-fill, 14px)">
                @foreach($this->jobs as $job)
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

                        $jobType = $job->snapshot ? __('Backup') : __('Restore');
                    @endphp
                    <button
                        wire:click="viewLogs('{{ $job->id }}')"
                        data-server="{{ $serverName }}"
                        data-database="{{ $databaseName }}"
                        data-type="{{ $jobType }}"
                        data-status="{{ ucfirst($job->status) }}"
                        data-duration="{{ $job->getHumanDuration() ?? '' }}"
                        data-ago="{{ $job->created_at?->diffForHumans(short: true) ?? '' }}"
                        data-date="{{ $job->created_at ? \App\Support\Formatters::humanDate($job->created_at) : '' }}"
                        @mouseenter="
                            let rect = $el.getBoundingClientRect();
                            $dispatch('show-job-tooltip', {
                                ...$el.dataset,
                                x: rect.left + rect.width / 2,
                                y: rect.top,
                            })
                        "
                        class="w-3.5 h-3.5 rounded-sm cursor-pointer transition-opacity hover:opacity-75 {{ $colorClass }}"
                    ></button>
                @endforeach
            </div>
        </div>

        {{-- Shared popover --}}
        <div
            x-data="{ show: false, server: '', database: '', type: '', status: '', duration: '', ago: '', date: '', x: 0, y: 0 }"
            x-on:show-job-tooltip.window="Object.assign($data, $event.detail); show = true"
            x-on:hide-job-tooltip.window="show = false"
        >
            <div
                x-show="show"
                x-cloak
                class="fixed z-50 pointer-events-none bg-base-300 text-base-content text-xs rounded-lg px-3 py-2 shadow-lg whitespace-nowrap -translate-x-1/2 -translate-y-full"
                :style="`left: ${x}px; top: ${y - 6}px;`"
            >
                <div class="font-semibold" x-text="database ? server + ' | ' + database : server"></div>
                <div class="text-base-content/70">
                    <span x-text="type"></span> | <span x-text="status"></span>
                    <span x-show="duration"> | <span x-text="duration"></span></span>
                </div>
                <div class="text-base-content/50" x-show="ago" x-text="ago + ' | ' + date"></div>
            </div>
        </div>
    @endif

    @if($this->jobs->isNotEmpty())
        <x-slot:actions class="!justify-start">
            <div class="flex items-center gap-3 text-xs text-base-content/70">
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-success inline-block"></span> {{ __('Completed') }}</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-error inline-block"></span> {{ __('Failed') }}</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-warning inline-block"></span> {{ __('Running') }}</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-info inline-block"></span> {{ __('Pending') }}</span>
            </div>
        </x-slot:actions>
    @endif

    @include('livewire.backup-job._logs-modal')
</x-card>
