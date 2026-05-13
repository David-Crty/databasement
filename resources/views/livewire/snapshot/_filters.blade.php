@php
    $isDesktop = $variant === 'desktop';
    $hasFilters = $search || $statusFilter !== '' || $serverFilter !== '' || $dbTypeFilter !== '' || $fileMissing !== '';
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
    <label class="flex items-center gap-1.5 cursor-pointer text-sm text-warning">
        <input type="checkbox" class="checkbox checkbox-warning checkbox-xs" wire:model.live="fileMissing" value="1" @checked($fileMissing !== '') />
        <x-icon name="o-exclamation-triangle" class="w-4 h-4" />
        {{ __('Missing') }}
    </label>
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
            <label class="flex items-center gap-1.5 cursor-pointer text-sm text-warning">
                <input type="checkbox" class="checkbox checkbox-warning checkbox-xs" wire:model.live="fileMissing" value="1" @checked($fileMissing !== '') />
                <x-icon name="o-exclamation-triangle" class="w-4 h-4" />
                {{ __('Missing') }}
            </label>
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
        <label class="flex items-center gap-2 cursor-pointer text-sm text-warning">
            <input type="checkbox" class="checkbox checkbox-warning checkbox-sm" wire:model.live="fileMissing" value="1" @checked($fileMissing !== '') />
            <x-icon name="o-exclamation-triangle" class="w-4 h-4" />
            {{ __('File missing') }}
        </label>
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
