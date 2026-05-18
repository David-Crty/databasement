@props([
    'value' => '',
    'label' => null,
    'monospace' => true,
])

<div
    x-data="{ text: @js($value), copied: false }"
    x-on:clipboard-copied="copied = true; setTimeout(() => copied = false, 2000)"
>
    <x-input
        :label="$label"
        :value="$value"
        readonly
        :class="$monospace ? 'font-mono text-xs' : ''"
    >
        <x-slot:append>
            <x-button
                type="button"
                x-clipboard="text"
                class="join-item btn-primary"
            >
                <span x-show="!copied" class="flex items-center gap-1.5">
                    <x-icon name="o-clipboard-document" class="w-4 h-4" />
                    {{ __('Copy') }}
                </span>
                <span x-show="copied" x-cloak class="flex items-center gap-1.5">
                    <x-icon name="s-check" class="w-4 h-4" />
                    {{ __('Copied') }}
                </span>
            </x-button>
        </x-slot:append>
    </x-input>
</div>
