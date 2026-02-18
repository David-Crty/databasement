@props(['type' => 'card'])

@if($type === 'card')
    <x-card class="animate-pulse">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-lg bg-base-300"></div>
            <div class="flex-1">
                <div class="h-4 w-20 bg-base-300 rounded mb-2"></div>
                <div class="h-6 w-16 bg-base-300 rounded"></div>
            </div>
        </div>
    </x-card>
@elseif($type === 'chart')
    <x-card shadow>
        <div class="animate-pulse">
            <div class="h-4 w-32 bg-base-300 rounded mb-1"></div>
            <div class="h-3 w-20 bg-base-300 rounded mb-4"></div>
            <div class="h-48 bg-base-300 rounded"></div>
        </div>
    </x-card>
@elseif($type === 'list')
    <x-card shadow class="h-full">
        <div class="animate-pulse">
            <div class="h-5 w-28 bg-base-300 rounded mb-4"></div>
            <div class="space-y-3">
                @for($i = 0; $i < 5; $i++)
                    <div class="flex items-center gap-3">
                        <div class="w-16 h-5 bg-base-300 rounded"></div>
                        <div class="flex-1 h-5 bg-base-300 rounded"></div>
                        <div class="w-20 h-5 bg-base-300 rounded"></div>
                    </div>
                @endfor
            </div>
        </div>
    </x-card>
@endif
