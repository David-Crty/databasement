@props([
    'value' => '',
    'label' => null,
    'monospace' => true,
    'multiline' => false,
    'rows' => 4,
])

{{-- The copy button reads the field's live value at click time (via $refs),
     so it stays correct even when the value is populated after the initial
     render (e.g. a token revealed inside an already-mounted modal). --}}
<div
    x-data="{ copied: false }"
    x-on:clipboard-copied="copied = true; setTimeout(() => copied = false, 2000)"
>
    @if ($multiline)
        @if ($label)
            <label class="fieldset-label mb-1 block text-sm">{{ $label }}</label>
        @endif
        <div class="relative">
            <x-textarea
                x-ref="source"
                readonly
                :rows="$rows"
                omit-error
                :class="$monospace ? 'font-mono text-xs pr-24' : 'pr-24'"
            >{{ $value }}</x-textarea>
            <x-button
                type="button"
                x-clipboard="$refs.source.value"
                class="btn-primary btn-xs absolute top-2 right-2 z-10"
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
        </div>
    @else
        <x-input
            x-ref="source"
            :label="$label"
            :value="$value"
            readonly
            :class="$monospace ? 'font-mono text-xs' : ''"
        >
            <x-slot:append>
                <x-button
                    type="button"
                    x-clipboard="$refs.source.value"
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
    @endif
</div>
