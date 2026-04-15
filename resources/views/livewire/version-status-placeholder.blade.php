<div class="inline-flex">
    @if($currentVersion)
    {{-- Up to date or no latest info — subtle version with green dot --}}
    <button
        class="inline-flex items-center gap-1.5 text-sm text-base-content/60 hover:text-base-content transition-colors cursor-pointer"
    >
        <span class="font-mono">{{ $currentVersion }}</span>
    </button>
    @else
        <button
            class="link link-hover text-sm text-base-content/60"
        >
            {{ __('How to update?') }}
        </button>
    @endif
</div>
