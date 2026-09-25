{{--
    Submit button for a classic (non-Livewire) form. Mary's `spinner` relies on
    wire:loading, so here the loading state is driven by the form's submit event.
--}}
@props([
    'label' => null,
    'icon' => null,
])

<x-button
    type="submit"
    x-data="{ submitting: false }"
    x-init="$el.form?.addEventListener('submit', () => submitting = true)"
    x-on:pageshow.window="submitting = false"
    x-bind:disabled="submitting"
    {{ $attributes }}
>
    <x-loading x-show="submitting" x-cloak class="loading-spinner w-5 h-5" />
    @if($icon)
        <span class="block" x-show="! submitting">
            <x-icon :name="$icon" />
        </span>
    @endif
    {{ $label ?? $slot }}
</x-button>
