<div>
    <x-card title="{{ __('Storage by Volume') }}" shadow class="h-full">
        @if($totalBytes === 0)
            <div class="h-48 flex items-center justify-center text-base-content/50">
                {{ __('No storage used yet') }}
            </div>
        @else
            <div class="h-48" x-data="chart(@js($chart), { formatBytes: true })">
                <canvas x-ref="canvas"></canvas>
            </div>
        @endif
    </x-card>
</div>
