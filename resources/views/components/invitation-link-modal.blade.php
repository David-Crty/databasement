@props(['title', 'message', 'doneAction' => null, 'doneLabel' => __('Done')])

<x-modal wire:model="showCopyModal" class="backdrop-blur" title="{{ $title }}" separator persistent>
    <p class="mb-4">{{ $message }}</p>

    <div class="flex gap-2">
        <div class="flex-1">
            <x-input
                wire:model="invitationUrl"
                readonly
                class="w-full"
            />
        </div>
        <x-button
            icon="o-clipboard-document"
            class="btn-primary"
            x-clipboard="$wire.invitationUrl"
            x-on:clipboard-copied="$wire.success('{{ __('Link copied to clipboard!') }}', null, 'toast-bottom')"
            tooltip="{{ __('Copy') }}"
        />
    </div>

    <x-slot:actions>
        @if($doneAction)
            <x-button label="{{ $doneLabel }}" wire:click="{{ $doneAction }}" class="btn-primary" />
        @else
            <x-button label="{{ $doneLabel }}" @click="$wire.showCopyModal = false" class="btn-primary" />
        @endif
    </x-slot:actions>
</x-modal>
