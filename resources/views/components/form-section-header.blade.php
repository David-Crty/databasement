@props(['step', 'title', 'subtitle' => null])

<div @class(['flex gap-3', 'items-start mb-6' => $subtitle, 'items-center mb-4' => ! $subtitle])>
    <span class="badge badge-primary badge-lg font-bold">{{ $step }}</span>
    @if($subtitle)
        <div>
            <h3 class="card-title text-lg leading-snug">{{ $title }}</h3>
            <p class="text-xs text-base-content/60 mt-0.5">{{ $subtitle }}</p>
        </div>
    @else
        <h3 class="card-title text-lg">{{ $title }}</h3>
    @endif
</div>
