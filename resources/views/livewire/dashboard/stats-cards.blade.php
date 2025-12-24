<div wire:init="load" class="grid gap-4 md:grid-cols-3">
    @if(!$loaded)
        @for($i = 0; $i < 3; $i++)
            <x-card class="animate-pulse">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-lg bg-base-300"></div>
                    <div class="flex-1">
                        <div class="h-4 w-20 bg-base-300 rounded mb-2"></div>
                        <div class="h-6 w-16 bg-base-300 rounded"></div>
                    </div>
                </div>
            </x-card>
        @endfor
    @else
        {{-- Total Snapshots --}}
        <x-card>
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-lg bg-primary/10 flex items-center justify-center">
                    <x-icon name="o-camera" class="w-6 h-6 text-primary" />
                </div>
                <div>
                    <div class="text-sm text-base-content/70">{{ __('Total Snapshots') }}</div>
                    <div class="text-2xl font-bold">{{ number_format($totalSnapshots) }}</div>
                </div>
            </div>
        </x-card>

        {{-- Total Storage --}}
        <x-card>
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-lg bg-secondary/10 flex items-center justify-center">
                    <x-icon name="o-circle-stack" class="w-6 h-6 text-secondary" />
                </div>
                <div>
                    <div class="text-sm text-base-content/70">{{ __('Total Storage') }}</div>
                    <div class="text-2xl font-bold">{{ $totalStorage }}</div>
                </div>
            </div>
        </x-card>

        {{-- Success Rate --}}
        <x-card>
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-lg {{ $successRate >= 90 ? 'bg-success/10' : ($successRate >= 70 ? 'bg-warning/10' : 'bg-error/10') }} flex items-center justify-center">
                    <x-icon name="o-chart-pie" class="w-6 h-6 {{ $successRate >= 90 ? 'text-success' : ($successRate >= 70 ? 'text-warning' : 'text-error') }}" />
                </div>
                <div>
                    <div class="text-sm text-base-content/70">{{ __('Success Rate (30d)') }}</div>
                    <div class="text-2xl font-bold flex items-center gap-2">
                        {{ $successRate }}%
                        @if($runningJobs > 0)
                            <span class="text-sm font-normal text-warning flex items-center gap-1">
                                <x-loading class="loading-xs" />
                                {{ $runningJobs }} {{ __('running') }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </x-card>
    @endif
</div>
