<div wire:init="load">
    <x-card title="{{ __('Jobs Activity') }}" subtitle="{{ __('Last 14 days') }}" shadow>
        @if(!$loaded)
            <div class="h-48 flex items-center justify-center">
                <x-loading class="loading-lg" />
            </div>
        @else
            <div class="h-48" x-data="chart(@js($chart))">
                <canvas x-ref="canvas"></canvas>
            </div>
        @endif
    </x-card>
</div>
