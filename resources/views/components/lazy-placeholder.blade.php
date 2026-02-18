<?php $type ??= 'card'; ?>

@if($type === 'card')
    <x-card>
        <div class="flex items-center gap-4">
            <div class="skeleton w-12 h-12 rounded-lg"></div>
            <div class="flex-1 space-y-2">
                <div class="skeleton h-4 w-20"></div>
                <div class="skeleton h-6 w-16"></div>
            </div>
        </div>
    </x-card>
@elseif($type === 'chart')
    <x-card shadow>
        <div class="skeleton h-4 w-32 mb-1"></div>
        <div class="skeleton h-3 w-20 mb-4"></div>
        <div class="skeleton h-48"></div>
    </x-card>
@elseif($type === 'list')
    <x-card shadow class="h-full">
        <div class="skeleton h-5 w-28 mb-4"></div>
        <div class="space-y-3">
            @for($i = 0; $i < 5; $i++)
                <div class="flex items-center gap-3">
                    <div class="skeleton w-16 h-5"></div>
                    <div class="skeleton flex-1 h-5"></div>
                    <div class="skeleton w-20 h-5"></div>
                </div>
            @endfor
        </div>
    </x-card>
@endif
