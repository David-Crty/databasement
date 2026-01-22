@php
    $isDesktop = $variant === 'desktop';
    $hasFilters = $search || $roleFilter !== '' || $statusFilter !== '';
@endphp

@if($isDesktop)
    {{-- Desktop: compact inline filters --}}
    <x-input
        placeholder="{{ __('Search...') }}"
        wire:model.live.debounce="search"
        clearable
        icon="o-magnifying-glass"
        class="!input-sm w-48"
    />
    <x-select
        placeholder="{{ __('All Roles') }}"
        placeholder-value=""
        wire:model.live="roleFilter"
        :options="$roleFilterOptions"
        class="!select-sm w-32"
    />
    <x-select
        placeholder="{{ __('All Status') }}"
        placeholder-value=""
        wire:model.live="statusFilter"
        :options="$statusFilterOptions"
        class="!select-sm w-32"
    />
    @if($hasFilters)
        <x-button
            icon="o-x-mark"
            wire:click="clear"
            spinner
            class="btn-ghost btn-sm"
            tooltip="{{ __('Clear filters') }}"
        />
    @endif
@else
    {{-- Tablet & Mobile: responsive filters --}}
    <div class="flex flex-wrap items-center gap-2">
        <x-input
            placeholder="{{ __('Search...') }}"
            wire:model.live.debounce="search"
            clearable
            icon="o-magnifying-glass"
            class="w-full sm:!input-sm"
        />
        {{-- Mobile: filter toggle --}}
        <x-button
            label="{{ __('Filters') }}"
            icon="o-funnel"
            @click="showFilters = !showFilters"
            class="btn-ghost btn-sm w-full justify-start sm:hidden"
            ::class="showFilters && 'btn-active'"
        />
        {{-- Tablet: inline filters (always visible) --}}
        <div class="hidden sm:flex flex-wrap items-center gap-2">
            <x-select
                placeholder="{{ __('All Roles') }}"
                placeholder-value=""
                wire:model.live="roleFilter"
                :options="$roleFilterOptions"
                class="!select-sm w-32"
            />
            <x-select
                placeholder="{{ __('All Status') }}"
                placeholder-value=""
                wire:model.live="statusFilter"
                :options="$statusFilterOptions"
                class="!select-sm w-32"
            />
            @if($hasFilters)
                <x-button
                    icon="o-x-mark"
                    wire:click="clear"
                    spinner
                    class="btn-ghost btn-sm"
                    tooltip="{{ __('Clear filters') }}"
                />
            @endif
        </div>
    </div>
    {{-- Mobile: collapsible filters --}}
    <div x-show="showFilters" x-collapse class="mt-3 space-y-3 sm:hidden">
        <x-select
            label="{{ __('Role') }}"
            placeholder="{{ __('All Roles') }}"
            placeholder-value=""
            wire:model.live="roleFilter"
            :options="$roleFilterOptions"
        />
        <x-select
            label="{{ __('Status') }}"
            placeholder="{{ __('All Status') }}"
            placeholder-value=""
            wire:model.live="statusFilter"
            :options="$statusFilterOptions"
        />
        @if($hasFilters)
            <x-button
                label="{{ __('Clear filters') }}"
                icon="o-x-mark"
                wire:click="clear"
                spinner
                class="btn-ghost btn-sm"
            />
        @endif
    </div>
@endif
