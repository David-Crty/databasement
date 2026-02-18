<div>
    <x-card title="{{ __('Jobs Activity') }}" subtitle="{{ __('Last 14 days') }}" shadow>
        <div class="h-48" x-data="chart(@js($chart))">
            <canvas x-ref="canvas"></canvas>
        </div>
    </x-card>
</div>
