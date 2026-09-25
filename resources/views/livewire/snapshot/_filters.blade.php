@php
    $isDesktop = $variant === 'desktop';
    $hasFilters = $search || $statusFilter !== '' || $serverFilter !== '' || $dbTypeFilter !== '' || $flagFilter !== '';
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
        :placeholder="__('All Servers')"
        placeholder-value=""
        wire:model.live="serverFilter"
        :options="$serverOptions"
        class="!select-sm w-36"
    />
    <x-select
        :placeholder="__('All Status')"
        placeholder-value=""
        wire:model.live="statusFilter"
        :options="$statusOptions"
        class="!select-sm w-32"
    />
    <x-select
        :placeholder="__('All Snapshots')"
        placeholder-value=""
        wire:model.live="flagFilter"
        :options="$flagOptions"
        class="!select-sm w-36"
    />
    @if($hasFilters)
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
            <x-select
                :placeholder="__('All Types')"
                placeholder-value=""
                wire:model.live="dbTypeFilter"
                :options="$dbTypeOptions"
                class="!select-sm w-40"
            />
            <x-select
                :placeholder="__('All Servers')"
                placeholder-value=""
                wire:model.live="serverFilter"
                :options="$serverOptions"
                class="!select-sm w-36"
            />
            <x-select
                :placeholder="__('All Status')"
                placeholder-value=""
                wire:model.live="statusFilter"
                :options="$statusOptions"
                class="!select-sm w-32"
            />
            <x-select
                :placeholder="__('All Snapshots')"
                placeholder-value=""
                wire:model.live="flagFilter"
                :options="$flagOptions"
                class="!select-sm w-36"
            />
            @if($hasFilters)
                <x-button
                    icon="o-x-mark"
                    wire:click="clear"
                    spinner
                    class="btn-ghost btn-sm"
                    :tooltip="__('Clear filters')"
                />
            @endif
        </div>
    </div>
    <div x-show="showFilters" x-collapse class="mt-3 space-y-3 sm:hidden">
        <x-select
            :label="__('Type')"
            :placeholder="__('All Types')"
            placeholder-value=""
            wire:model.live="dbTypeFilter"
            :options="$dbTypeOptions"
        />
        <x-select
            :label="__('Server')"
            :placeholder="__('All Servers')"
            placeholder-value=""
            wire:model.live="serverFilter"
            :options="$serverOptions"
        />
        <x-select
            :label="__('Status')"
            :placeholder="__('All Status')"
            placeholder-value=""
            wire:model.live="statusFilter"
            :options="$statusOptions"
        />
        <x-select
            :label="__('Show')"
            :placeholder="__('All Snapshots')"
            placeholder-value=""
            wire:model.live="flagFilter"
            :options="$flagOptions"
        />
        @if($hasFilters)
            <x-button
                :label="__('Clear filters')"
                icon="o-x-mark"
                wire:click="clear"
                spinner
                class="btn-ghost btn-sm"
            />
        @endif
    </div>
@endif
