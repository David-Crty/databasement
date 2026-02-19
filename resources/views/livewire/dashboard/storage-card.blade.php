<x-card>
    <div class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-lg bg-secondary/10 flex items-center justify-center">
            <x-icon name="o-circle-stack" class="w-6 h-6 text-secondary"/>
        </div>
        <div>
            <div class="text-sm text-base-content/70">{{ __('Total Storage') }}</div>
            <div class="text-2xl font-bold">{{ $totalStorage }}</div>
        </div>
    </div>
</x-card>
