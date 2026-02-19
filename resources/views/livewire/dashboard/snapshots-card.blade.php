<x-card>
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
                :disabled="!auth()->user()->isAdmin()"
            />
        </div>

        {{-- Count + status badge --}}
        <div class="flex items-end justify-between">
            <div class="flex items-baseline gap-1.5">
                <span class="text-3xl font-bold tabular-nums">{{ number_format($totalSnapshots) }}</span>
                <span class="text-sm text-base-content/40">{{ __('snapshots') }}</span>
            </div>

            @if($missingSnapshots > 0)
                <a href="{{ route('jobs.index', ['fileMissing' => '1']) }}"
                   class="badge badge-warning badge-sm gap-1 py-2.5 hover:brightness-95 transition-all" wire:navigate>
                    <x-icon name="o-exclamation-triangle" class="w-3.5 h-3.5"/>
                    {{ $missingSnapshots }} {{ __('missing') }}
                    <x-icon name="o-chevron-right" class="w-3 h-3 opacity-60"/>
                </a>
            @elseif($verifiedSnapshots > 0 && $verifiedSnapshots === $totalSnapshots)
                <span class="badge badge-success badge-sm gap-1 py-2.5">
                        <x-icon name="o-check-circle" class="w-3.5 h-3.5"/>
                        {{ __('All verified') }}
                    </span>
            @elseif($totalSnapshots > 0)
                <span class="badge badge-ghost badge-sm gap-1 py-2.5">
                        <x-icon name="o-clock" class="w-3.5 h-3.5"/>
                        {{ $verifiedSnapshots }}/{{ $totalSnapshots }}
                    </span>
            @endif
        </div>

    </div>
</x-card>
