<div>
    <x-header :title="__('Configuration')" separator>
        <x-slot:subtitle>
            {{ __('Manage organizations and their resources.') }}
        </x-slot:subtitle>
    </x-header>

    @include('livewire.configuration._tabs', ['active' => 'organizations'])

    <x-alert icon="o-information-circle" class="alert-info mb-4">
        {{ __('Organizations let you group users, servers, and volumes into isolated workspaces.') }}
        <a href="https://david-crty.github.io/databasement/docs/user-guide/organizations" target="_blank" rel="noopener noreferrer" class="link link-primary">{{ __('Learn more') }}</a>
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
                    <div class="text-right">
                        <x-button icon="o-pencil" class="btn-ghost btn-xs" wire:click="openEditModal('{{ $org->id }}')" :tooltip="__('Edit')" />
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

    {{-- Delete Confirmation --}}
    <x-modal wire:model="showDeleteModal" :title="__('Delete Organization')">
        @if($deleteOrgHasResources)
            <x-alert icon="o-exclamation-triangle" class="alert-warning">
                {{ __('This organization still has servers, volumes, or agents. Remove all resources before deleting it.') }}
            </x-alert>
        @else
            <p>{{ __('Are you sure you want to delete this organization? This action cannot be undone.') }}</p>
        @endif
        <x-slot:actions>
            <x-button :label="__('Cancel')" @click="$wire.showDeleteModal = false" />
            @unless($deleteOrgHasResources)
                <x-button :label="__('Delete')" class="btn-error" wire:click="deleteOrganization" />
            @endunless
        </x-slot:actions>
    </x-modal>
</div>
