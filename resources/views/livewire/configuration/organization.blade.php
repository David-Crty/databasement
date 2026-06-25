<div>
    <x-header :title="__('Configuration')" separator>
        <x-slot:subtitle>
            {{ __('Manage organizations and their resources.') }}
        </x-slot:subtitle>
    </x-header>

    @include('livewire.configuration._tabs', ['active' => 'organizations'])

    <x-alert icon="o-information-circle" class="alert-info mb-4">
        {{ __('Organizations let you group users, servers, and volumes into isolated workspaces.') }}
        <x-slot:actions>
            <x-button
                :label="__('Learn more')"
                link="https://david-crty.github.io/databasement/user-guide/organizations"
                external
                icon="o-book-open"
                class="btn-sm"
            />
        </x-slot:actions>
    </x-alert>

    <div class="flex justify-end mb-4">
        <x-button :label="__('New Organization')" icon="o-plus" class="btn-primary" wire:click="openCreateModal" />
    </div>

    <x-card shadow>
        <x-table :headers="$headers" :rows="$organizations">
            @scope('cell_name', $org)
                <div class="flex items-center gap-2">
                    {{ $org->name }}
                    @if($org->is_default)
                        <x-popover>
                            <x-slot:trigger>
                                <x-icon name="o-lock-closed" class="w-4 h-4 text-base-content/50 cursor-pointer" />
                            </x-slot:trigger>
                            <x-slot:content>
                                {{ __('The default organization cannot be edited or deleted.') }}
                            </x-slot:content>
                        </x-popover>
                    @endif
                </div>
            @endscope

            @scope('cell_id', $org)
                <code class="text-xs">{{ $org->id }}</code>
            @endscope

            @scope('cell_actions', $org)
                @unless($org->is_default)
                    <div class="flex justify-end flex-nowrap gap-1">
                        <x-button icon="o-pencil" class="btn-ghost btn-xs" wire:click="openEditModal('{{ $org->id }}')" :tooltip="__('Edit')" />
                        <x-button icon="o-arrows-pointing-in" class="btn-ghost btn-xs" wire:click="openMergeModal('{{ $org->id }}')" :tooltip="__('Merge')" />
                        <x-button icon="o-trash" class="btn-ghost btn-xs text-error" wire:click="confirmDelete('{{ $org->id }}')" :tooltip="__('Delete')" />
                    </div>
                @endunless
            @endscope
        </x-table>
    </x-card>

    {{-- Create Modal --}}
    <x-modal wire:model="showCreateModal" :title="__('Create Organization')">
        <x-input :label="__('Name')" wire:model="newOrgName" />
        <x-slot:actions>
            <x-button :label="__('Cancel')" @click="$wire.showCreateModal = false" />
            <x-button :label="__('Create')" class="btn-primary" wire:click="createOrganization" />
        </x-slot:actions>
    </x-modal>

    {{-- Edit Modal --}}
    <x-modal wire:model="showEditModal" :title="__('Edit Organization')">
        <x-input :label="__('Name')" wire:model="editOrgName" />
        <x-slot:actions>
            <x-button :label="__('Cancel')" @click="$wire.showEditModal = false" />
            <x-button :label="__('Save')" class="btn-primary" wire:click="updateOrganization" />
        </x-slot:actions>
    </x-modal>

    {{-- Merge Modal --}}
    <x-modal wire:model="showMergeModal" :title="__('Merge Organization')">
        <x-alert icon="o-exclamation-triangle" class="alert-warning mb-4">
            {{ __('All servers, volumes, agents, jobs and snapshots will be moved to the destination organization, and this organization will be deleted. This action cannot be undone.') }}
        </x-alert>
        <x-select
            :label="__('Destination organization')"
            wire:model="mergeDestinationId"
            :options="$this->mergeDestinations()"
            :placeholder="__('Select a destination')"
        />
        <x-slot:actions>
            <x-button :label="__('Cancel')" @click="$wire.showMergeModal = false" />
            <x-button :label="__('Merge')" class="btn-primary" wire:click="mergeOrganization" />
        </x-slot:actions>
    </x-modal>

    {{-- Delete Confirmation --}}
    <x-modal wire:model="showDeleteModal" :title="__('Delete Organization')">
        <x-alert icon="o-exclamation-triangle" class="alert-warning">
            {{ __('All servers, volumes, agents and snapshots in this organization will be permanently deleted. This action cannot be undone.') }}
        </x-alert>

        <label class="flex items-start gap-3 mt-4 cursor-pointer">
            <input type="checkbox" wire:model="keepFiles" class="checkbox checkbox-sm mt-0.5" />
            <span class="text-sm">{{ __('Keep backup files on storage (only delete database records)') }}</span>
        </label>
        <x-slot:actions>
            <x-button :label="__('Cancel')" @click="$wire.showDeleteModal = false" />
            <x-button :label="__('Delete')" class="btn-error" wire:click="deleteOrganization" spinner="deleteOrganization" />
        </x-slot:actions>
    </x-modal>
</div>
