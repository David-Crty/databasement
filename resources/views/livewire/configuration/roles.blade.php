<div>
    <x-header :title="__('Configuration')" separator>
        <x-slot:subtitle>
            {{ __('Define what each role can do. Changes apply immediately, no redeploy needed.') }}
        </x-slot:subtitle>
    </x-header>

    @include('livewire.configuration._tabs', ['active' => 'roles'])

    <x-card shadow class="min-w-0">
        <x-card-heading :title="__('Roles')" :subtitle="__('Roles bundle abilities that control what users can do.')">
            <x-button
                :label="__('Documentation')"
                icon="o-book-open"
                link="https://david-crty.github.io/databasement/user-guide/permissions"
                external
                class="btn-ghost btn-sm"
            />
            @can('create', \Silber\Bouncer\Database\Role::class)
                <x-button :label="__('New role')" icon="o-plus" wire:click="openCreate" class="btn-primary btn-sm" />
            @endcan
        </x-card-heading>

        <x-table :headers="$headers" :rows="$roles" show-empty-text :empty-text="__('No roles yet.')">
            @scope('cell_name', $role)
                <div class="font-medium">{{ $role->title ?: $role->name }}</div>
                <div class="text-sm text-base-content/60 flex items-center gap-1">
                    <code>{{ $role->name }}</code>
                    @if($role->built_in)
                        <x-badge :value="__('Built-in')" class="badge-ghost badge-xs whitespace-nowrap" />
                    @endif
                </div>
            @endscope

            @scope('cell_abilities', $role)
                <x-ability-badges :abilities="$role->abilities->pluck('name')->all()" />
            @endscope

            @scope('cell_members', $role, $memberCounts)
                {{ $memberCounts[$role->id] ?? 0 }}
            @endscope

            @scope('cell_actions', $role)
                <div class="flex justify-end flex-nowrap gap-1">
                    @can('update', $role)
                        <x-button icon="o-pencil" class="btn-ghost btn-xs tooltip tooltip-left" wire:click="openEdit({{ $role->id }})" :tooltip-left="__('Edit')" />
                    @endcan
                    @can('delete', $role)
                        <x-button icon="o-trash" class="btn-ghost btn-xs text-error tooltip tooltip-left" wire:click="confirmDelete({{ $role->id }})" :tooltip-left="__('Delete')" />
                    @endcan
                </div>
            @endscope
        </x-table>
    </x-card>

    <!-- CREATE / EDIT MODAL -->
    <x-modal wire:model="showFormModal" :title="$editingId ? __('Edit role') : __('New role')" box-class="max-w-2xl" class="backdrop-blur">
        <div class="space-y-4">
            <x-input :label="__('Name')" wire:model="title" :placeholder="__('e.g. Backup Operator')" />

            <div>
                <div class="font-medium mb-2">{{ __('Abilities') }}</div>
                <x-ability-toggles :groups="$abilityGroups" model="abilities" />
            </div>
        </div>

        <x-slot:actions>
            <x-button :label="__('Cancel')" @click="$wire.showFormModal = false" />
            <x-button :label="__('Save')" class="btn-primary" wire:click="save" spinner="save" />
        </x-slot:actions>
    </x-modal>

    <!-- DELETE MODAL -->
    <x-modal wire:model="showDeleteModal" :title="__('Delete role')" class="backdrop-blur">
        <p>{{ __('Are you sure you want to delete this role? Users who have only this role will lose its abilities.') }}</p>
        <x-slot:actions>
            <x-button :label="__('Cancel')" @click="$wire.showDeleteModal = false" />
            <x-button :label="__('Delete')" class="btn-error" wire:click="delete" spinner="delete" />
        </x-slot:actions>
    </x-modal>
</div>
