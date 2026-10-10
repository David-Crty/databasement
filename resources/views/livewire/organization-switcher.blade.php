<div>
    <div class="hidden-when-collapsed">
        <x-select
            :options="$organizations"
            option-value="id"
            option-label="name"
            wire:model="currentOrgId"
            wire:change="switchOrg($event.target.value)"
            icon="o-building-office"
            class="select-sm"
        />
    </div>

    <div class="display-when-collapsed hidden">
        <x-button
            icon="o-building-office"
            class="btn-ghost btn-sm btn-square"
            :tooltip-right="collect($organizations)->firstWhere('id', $currentOrgId)['name'] ?? null"
            x-data
            @click="$dispatch('menu-sub-clicked')"
        />
    </div>
</div>
