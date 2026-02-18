<div class="grid gap-4 md:grid-cols-3">
    {{-- Total Snapshots --}}
    <x-card id="snapshots">
        <div class="space-y-3">
            {{-- Header: label + verify button --}}
            <div class="flex items-center justify-between">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-base-content/50">{{ __('Snapshots') }}</h3>
                <x-button
                    icon="o-arrow-path"
                    label="{{ __('Verify') }}"
                    wire:click="verifyFiles"
                    spinner
                    class="btn-ghost btn-xs text-base-content/60"
                />
            </div>

            {{-- Count + status badge --}}
            <div class="flex items-end justify-between">
                <div class="flex items-baseline gap-1.5">
                    <span class="text-3xl font-bold tabular-nums">{{ number_format($totalSnapshots) }}</span>
                    <span class="text-sm text-base-content/40">{{ __('snapshots') }}</span>
                </div>

                @if($missingSnapshots > 0)
                    <a href="{{ route('jobs.index', ['fileMissing' => '1']) }}" class="badge badge-warning badge-sm gap-1 py-2.5 hover:brightness-95 transition-all" wire:navigate>
                        <x-icon name="o-exclamation-triangle" class="w-3.5 h-3.5" />
                        {{ $missingSnapshots }} {{ __('missing') }}
                        <x-icon name="o-chevron-right" class="w-3 h-3 opacity-60" />
                    </a>
                @elseif($verifiedSnapshots > 0 && $verifiedSnapshots === $totalSnapshots)
                    <span class="badge badge-success badge-sm gap-1 py-2.5">
                        <x-icon name="o-check-circle" class="w-3.5 h-3.5" />
                        {{ __('All verified') }}
                    </span>
                @elseif($totalSnapshots > 0)
                    <span class="badge badge-ghost badge-sm gap-1 py-2.5">
                        <x-icon name="o-clock" class="w-3.5 h-3.5" />
                        {{ $verifiedSnapshots }}/{{ $totalSnapshots }}
                    </span>
                @endif
            </div>

        </div>
    </x-card>

    {{-- Total Storage --}}
    <x-card id="storage">
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
    <x-card id="success-rate">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-lg {{ $this->successRateColor['bg'] }} flex items-center justify-center">
                <x-icon name="o-chart-pie" class="w-6 h-6 {{ $this->successRateColor['text'] }}" />
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
</div>
