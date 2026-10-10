@php
    $isDesktop = $variant === 'desktop';
@endphp

@if($isDesktop)
    <x-input
        :placeholder="__('Search...')"
        wire:model.live.debounce="search"
        clearable
        icon="o-magnifying-glass"
        class="!input-sm w-48"
    />
    <x-select
        :placeholder="__('All Types')"
        placeholder-value=""
        wire:model.live="dbTypeFilter"
        :options="$dbTypeOptions"
        class="!select-sm w-40"
    />
    <x-select
        :placeholder="__('Source Server')"
        placeholder-value=""
        wire:model.live="sourceServerFilter"
        :options="$sourceOptions"
        class="!select-sm w-40"
    />
    <x-select
        :placeholder="__('Target Server')"
        placeholder-value=""
        wire:model.live="targetServerFilter"
        :options="$targetOptions"
        class="!select-sm w-40"
    />
    <x-select
        :placeholder="$statusPlaceholder"
        placeholder-value=""
        wire:model.live="{{ $statusModel }}"
        :options="$statusOptions"
        class="!select-sm w-32"
    />
    @if($this->hasFilters)
        <x-button
            icon="o-x-mark"
            wire:click="clear"
            spinner
            class="btn-ghost btn-sm"
            :tooltip="__('Clear filters')"
        />
    @endif
@else
    <div class="flex flex-wrap items-center gap-2">
        <x-input
            :placeholder="__('Search...')"
            wire:model.live.debounce="search"
            clearable
            icon="o-magnifying-glass"
            class="w-full sm:!input-sm"
        />
        <x-button
            :label="__('Filters')"
            icon="o-funnel"
            @click="showFilters = !showFilters"
            class="btn-ghost btn-sm w-full justify-start sm:hidden"
            ::class="showFilters && 'btn-active'"
        />
        <div class="hidden sm:flex flex-wrap items-center gap-2">
            <x-select :placeholder="__('All Types')" placeholder-value="" wire:model.live="dbTypeFilter" :options="$dbTypeOptions" class="!select-sm w-40" />
            <x-select :placeholder="__('Source')" placeholder-value="" wire:model.live="sourceServerFilter" :options="$sourceOptions" class="!select-sm w-36" />
            <x-select :placeholder="__('Target')" placeholder-value="" wire:model.live="targetServerFilter" :options="$targetOptions" class="!select-sm w-36" />
            <x-select :placeholder="$statusPlaceholder" placeholder-value="" wire:model.live="{{ $statusModel }}" :options="$statusOptions" class="!select-sm w-32" />
            @if($this->hasFilters)
                <x-button icon="o-x-mark" wire:click="clear" spinner class="btn-ghost btn-sm" :tooltip="__('Clear filters')" />
            @endif
        </div>
    </div>
    <div x-show="showFilters" x-collapse class="mt-3 space-y-3 sm:hidden">
        <x-select :label="__('Type')" :placeholder="__('All Types')" placeholder-value="" wire:model.live="dbTypeFilter" :options="$dbTypeOptions" />
        <x-select :label="__('Source Server')" :placeholder="__('All Servers')" placeholder-value="" wire:model.live="sourceServerFilter" :options="$sourceOptions" />
        <x-select :label="__('Target Server')" :placeholder="__('All Servers')" placeholder-value="" wire:model.live="targetServerFilter" :options="$targetOptions" />
        <x-select :label="__('Status')" :placeholder="$statusPlaceholder" placeholder-value="" wire:model.live="{{ $statusModel }}" :options="$statusOptions" />
        @if($this->hasFilters)
            <x-button :label="__('Clear filters')" icon="o-x-mark" wire:click="clear" spinner class="btn-ghost btn-sm" />
        @endif
    </div>
@endif
