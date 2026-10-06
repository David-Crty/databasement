@php
    $canEditComment = auth()->user()->can('update', $snapshot);
    $isEditingComment = $this->editCommentSnapshotId === $snapshot->id;
@endphp

@if($isEditingComment)
    <div class="mt-1 max-w-md" wire:key="comment-editor-{{ $snapshot->id }}">
        <x-textarea
            wire:model="commentDraft"
            rows="2"
            maxlength="1000"
            :aria-label="__('Comment')"
            :placeholder="__('e.g. Backup before upgrading to version 1.37')"
            :hint="__('Ctrl/⌘ + Enter to save, Esc to cancel')"
            hint-class="fieldset-label hidden sm:block"
            class="textarea-sm leading-6"
            x-init="$nextTick(() => { $el.focus(); $el.setSelectionRange($el.value.length, $el.value.length) })"
            x-on:keydown.escape.prevent.stop="$wire.cancelEditComment()"
            x-on:keydown.meta.enter.prevent="$wire.saveComment()"
            x-on:keydown.ctrl.enter.prevent="$wire.saveComment()"
        />

        <div class="flex items-center justify-end gap-1 mt-1">
            <x-button :label="__('Cancel')" class="btn-ghost btn-xs" wire:click="cancelEditComment" spinner />
            <x-button :label="__('Save')" class="btn-primary btn-xs" wire:click="saveComment" spinner />
        </div>
    </div>
@elseif($snapshot->comment)
    <div class="flex items-start gap-1.5 mt-1 min-w-0 max-w-md">
        <x-icon name="o-chat-bubble-bottom-center-text" class="w-3.5 h-3.5 mt-1 shrink-0 text-base-content/40" />

        {{-- Editing shows the full text in the textarea, so read-only users get an expand toggle instead --}}
        <button
            type="button"
            class="link link-hover min-w-0 text-start text-sm text-base-content/70 line-clamp-2 hover:text-base-content"
            @if($canEditComment)
                wire:click="editComment('{{ $snapshot->id }}')"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-50"
                wire:target="editComment('{{ $snapshot->id }}')"
                aria-label="{{ __('Edit comment') }}: {{ $snapshot->comment }}"
            @else
                x-data="{ expanded: false }"
                x-on:click="expanded = ! expanded"
                x-bind:class="{ 'line-clamp-2': ! expanded }"
                x-bind:aria-expanded="expanded"
            @endif
        >{{ $snapshot->comment }}</button>
    </div>
@elseif($canEditComment)
    <x-button
        :label="__('Add comment')"
        icon="o-plus"
        wire:click="editComment('{{ $snapshot->id }}')"
        spinner
        class="btn-ghost btn-xs -ms-2 mt-1 font-normal text-base-content/50 transition-opacity hover:text-primary md:opacity-0 md:group-hover:opacity-100 md:focus-visible:opacity-100"
    />
@endif
