<div>
    <x-header :title="__('Configuration')" separator>
        <x-slot:subtitle>
            {{ __('Manage organizations and their resources.') }}
        </x-slot:subtitle>
    </x-header>

    @include('livewire.configuration._tabs', ['active' => 'organizations'])

    <x-card shadow class="min-w-0">
        <x-card-heading :title="__('Organizations')" :subtitle="__('Organizations let you group users, servers, and volumes into isolated workspaces.')">
            <x-button
                :label="__('Documentation')"
                icon="o-book-open"
                link="https://david-crty.github.io/databasement/user-guide/organizations"
                external
                class="btn-ghost btn-sm"
            />
            @can('create', \App\Models\Organization::class)
                <x-button :label="__('New Organization')" icon="o-plus" class="btn-primary btn-sm" wire:click="openCreateModal" />
            @endcan
        </x-card-heading>

        <x-table :headers="$headers" :rows="$organizations" show-empty-text :empty-text="__('No organizations yet.')">
            @scope('cell_name', $org)
                <div class="flex items-center gap-2">
                    <span class="font-medium">{{ $org->name }}</span>
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
                {{-- update/delete policies already return false for the default org and non-super-admins --}}
                @can('update', $org)
                    <div class="flex justify-end flex-nowrap gap-1">
                        <x-button icon="o-pencil" class="btn-ghost btn-xs tooltip tooltip-left" wire:click="openEditModal('{{ $org->id }}')" :tooltip-left="__('Edit')" />
                        <x-button icon="o-arrows-pointing-in" class="btn-ghost btn-xs tooltip tooltip-left" wire:click="openMergeModal('{{ $org->id }}')" :tooltip-left="__('Merge')" />
                        <x-button icon="o-trash" class="btn-ghost btn-xs text-error tooltip tooltip-left" wire:click="confirmDelete('{{ $org->id }}')" :tooltip-left="__('Delete')" />
                    </div>
                @endcan
            @endscope
        </x-table>
    </x-card>

    @can('create', \App\Models\Organization::class)
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
    @endcan
</div>
