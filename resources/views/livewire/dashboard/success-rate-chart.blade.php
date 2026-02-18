<div>
    <x-card title="{{ __('Job Status') }}" subtitle="{{ __('Last 30 days') }}" shadow class="h-full">
        @if($total === 0)
            <div class="h-48 flex items-center justify-center text-base-content/50">
                {{ __('No jobs in the last 30 days') }}
            </div>
        @else
            <div class="h-48" x-data="chart(@js($chart))">
                <canvas x-ref="canvas"></canvas>
            </div>
        @endif
    </x-card>
</div>
