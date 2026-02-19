<x-card>
    <div class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-lg {{ $this->successRateColor['bg'] }} flex items-center justify-center">
            <x-icon name="o-chart-pie" class="w-6 h-6 {{ $this->successRateColor['text'] }}"/>
        </div>
        <div>
            <div class="text-sm text-base-content/70">{{ __('Success Rate (30d)') }}</div>
            <div class="text-2xl font-bold flex items-center gap-2">
                {{ $successRate }}%
                @if($runningJobs > 0)
                    <span class="text-sm font-normal text-warning flex items-center gap-1">
                            <x-loading class="loading-xs"/>
                            {{ $runningJobs }} {{ __('running') }}
                        </span>
                @endif
            </div>
        </div>
    </div>
</x-card>
