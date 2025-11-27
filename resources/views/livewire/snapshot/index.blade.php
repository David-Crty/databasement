<div>
    <!-- HEADER -->
    <x-header title="{{ __('Snapshots') }}" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            <x-input placeholder="{{ __('Search...') }}" wire:model.live.debounce="search" clearable icon="o-magnifying-glass" />
        </x-slot:middle>
        <x-slot:actions>
            <x-button label="{{ __('Filters') }}" @click="$wire.drawer = true" responsive icon="o-funnel" class="btn-ghost" />
        </x-slot:actions>
    </x-header>

    <!-- TABLE -->
    <x-card shadow>
        <x-table :headers="$headers" :rows="$snapshots" :sort-by="$sortBy" with-pagination>
            <x-slot:empty>
                <div class="text-center text-base-content/50 py-8">
                    @if($search || $statusFilter !== 'all')
                        {{ __('No snapshots found matching your filters.') }}
                    @else
                        {{ __('No snapshots yet. Create a backup from the Database Servers page.') }}
                    @endif
                </div>
            </x-slot:empty>

            @scope('cell_started_at', $snapshot)
                <div class="table-cell-primary">{{ $snapshot->started_at->format('M d, Y H:i') }}</div>
                <div class="text-sm text-base-content/70">{{ $snapshot->started_at->diffForHumans() }}</div>
            @endscope

            @scope('cell_server', $snapshot)
                <div class="table-cell-primary">{{ $snapshot->databaseServer->name }}</div>
                <div><x-badge :value="$snapshot->database_type" /></div>
            @endscope

            @scope('cell_database', $snapshot)
                <div class="table-cell-primary">{{ $snapshot->database_name }}</div>
            @endscope

            @scope('cell_status', $snapshot)
                @if($snapshot->status === 'completed')
                    <x-badge value="{{ __('Completed') }}" class="badge-success" />
                @elseif($snapshot->status === 'failed')
                    <x-badge value="{{ __('Failed') }}" class="badge-error" />
                @elseif($snapshot->status === 'running')
                    <x-badge value="{{ __('Running') }}" class="badge-warning" />
                @else
                    <x-badge value="{{ __('Pending') }}" class="badge-info" />
                @endif
            @endscope

            @scope('cell_duration', $snapshot)
                @if($snapshot->getHumanDuration())
                    {{ $snapshot->getHumanDuration() }}
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('cell_size', $snapshot)
                <div class="table-cell-primary">{{ $snapshot->getHumanFileSize() }}</div>
                @if($snapshot->database_size_bytes)
                    <div class="text-sm text-base-content/70">DB: {{ $snapshot->getHumanDatabaseSize() }}</div>
                @endif
            @endscope

            @scope('cell_method', $snapshot)
                <x-badge :value="ucfirst($snapshot->method)" />
            @endscope

            @scope('actions', $snapshot)
                <div class="flex gap-2 justify-end">
                    @if($snapshot->status === 'completed')
                        <x-button
                            icon="o-arrow-down-tray"
                            wire:click="download('{{ $snapshot->id }}')"
                            spinner
                            tooltip="{{ __('Download') }}"
                            class="btn-ghost btn-sm text-info"
                        />
                    @endif
                    <x-button
                        icon="o-trash"
                        wire:click="confirmDelete('{{ $snapshot->id }}')"
                        tooltip="{{ __('Delete') }}"
                        class="btn-ghost btn-sm text-error"
                    />
                </div>
            @endscope
        </x-table>
    </x-card>

    <!-- FILTER DRAWER -->
    <x-drawer wire:model="drawer" title="{{ __('Filters') }}" right separator with-close-button class="lg:w-1/3">
        <div class="grid gap-5">
            <x-input placeholder="{{ __('Search...') }}" wire:model.live.debounce="search" icon="o-magnifying-glass" @keydown.enter="$wire.drawer = false" />
            <x-select
                placeholder="{{ __('Filter by status') }}"
                wire:model.live="statusFilter"
                :options="$statusOptions"
                icon="o-funnel"
            />
        </div>

        <x-slot:actions>
            <x-button label="{{ __('Reset') }}" icon="o-x-mark" wire:click="clear" spinner />
            <x-button label="{{ __('Done') }}" icon="o-check" class="btn-primary" @click="$wire.drawer = false" />
        </x-slot:actions>
    </x-drawer>

    <!-- DELETE CONFIRMATION MODAL -->
    <x-delete-confirmation-modal
        :title="__('Delete Snapshot')"
        :message="__('Are you sure you want to delete this snapshot? The backup file will be permanently removed.')"
        onConfirm="delete"
    />
</div>
