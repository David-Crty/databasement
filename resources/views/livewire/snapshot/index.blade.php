@php
$statusOptions = [
    ['id' => 'all', 'name' => __('All Statuses')],
    ['id' => 'completed', 'name' => __('Completed')],
    ['id' => 'failed', 'name' => __('Failed')],
    ['id' => 'running', 'name' => __('Running')],
    ['id' => 'pending', 'name' => __('Pending')],
];
@endphp

<div>
    <div class="mx-auto max-w-7xl">
        <!-- Header -->
        <div class="mb-6 flex items-center justify-between">
            <div>
                <x-header title="{{ __('Snapshots') }}" subtitle="{{ __('View and manage database backup snapshots') }}" size="text-2xl" separator />
            </div>
        </div>

        @if (session('status'))
            <x-alert class="alert-success mb-6" icon="o-check-circle" dismissible>
                {{ session('status') }}
            </x-alert>
        @endif

        @if (session('error'))
            <x-alert class="alert-error mb-6" icon="o-x-circle" dismissible>
                {{ session('error') }}
            </x-alert>
        @endif

        <x-card :padding="false">
            <!-- Filters -->
            <div class="border-b border-base-300 p-4">
                <div class="flex flex-col gap-4 sm:flex-row">
                    <div class="flex-1">
                        <x-input
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search by server, database, host, or filename...') }}"
                            icon="o-magnifying-glass"
                            type="search"
                            clearable
                        />
                    </div>
                    <div class="sm:w-48">
                        <x-select
                            wire:model.live="statusFilter"
                            :options="$statusOptions"
                        />
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="table-default w-full">
                    <thead>
                        <tr>
                            <th class="table-th">
                                <button wire:click="sortBy('started_at')" class="group table-th-sortable">
                                    {{ __('Started') }}
                                    <span class="opacity-50">
                                        @if($sortField === 'started_at')
                                            <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4" />
                                        @else
                                            <x-icon name="o-arrows-up-down" class="w-4 h-4 opacity-0 group-hover:opacity-50" />
                                        @endif
                                    </span>
                                </button>
                            </th>
                            <th class="table-th">{{ __('Server') }}</th>
                            <th class="table-th">{{ __('Database') }}</th>
                            <th class="table-th">{{ __('Status') }}</th>
                            <th class="table-th">{{ __('Duration') }}</th>
                            <th class="table-th">{{ __('Size') }}</th>
                            <th class="table-th">{{ __('Method') }}</th>
                            <th class="table-th-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($snapshots as $snapshot)
                            <tr>
                                <td>
                                    <div class="table-cell-primary">{{ $snapshot->started_at->format('M d, Y H:i') }}</div>
                                    <div>{{ $snapshot->started_at->diffForHumans() }}</div>
                                </td>
                                <td>
                                    <div class="table-cell-primary">{{ $snapshot->databaseServer->name }}</div>
                                    <div>{{ $snapshot->database_host }}:{{ $snapshot->database_port }}</div>
                                </td>
                                <td>
                                    <div class="table-cell-primary">{{ $snapshot->database_name }}</div>
                                    <div><x-table-badge>{{ $snapshot->database_type }}</x-table-badge></div>
                                </td>
                                <td>
                                    @if($snapshot->status === 'completed')
                                        <x-table-badge variant="success">{{ __('Completed') }}</x-table-badge>
                                    @elseif($snapshot->status === 'failed')
                                        <x-table-badge variant="danger">{{ __('Failed') }}</x-table-badge>
                                    @elseif($snapshot->status === 'running')
                                        <x-table-badge variant="warning">{{ __('Running') }}</x-table-badge>
                                    @else
                                        <x-table-badge variant="info">{{ __('Pending') }}</x-table-badge>
                                    @endif
                                </td>
                                <td>
                                    @if($snapshot->getHumanDuration())
                                        {{ $snapshot->getHumanDuration() }}
                                    @else
                                        <span class="opacity-50">-</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="table-cell-primary">{{ $snapshot->getHumanFileSize() }}</div>
                                    @if($snapshot->database_size_bytes)
                                        <div>DB: {{ $snapshot->getHumanDatabaseSize() }}</div>
                                    @endif
                                </td>
                                <td>
                                    <x-table-badge>{{ ucfirst($snapshot->method) }}</x-table-badge>
                                </td>
                                <td class="text-right">
                                    <div class="table-actions">
                                        @if($snapshot->status === 'completed')
                                            <x-button
                                                class="btn-ghost btn-sm text-info"
                                                icon="o-arrow-down-tray"
                                                wire:click="download('{{ $snapshot->id }}')"
                                            >
                                                {{ __('Download') }}
                                            </x-button>
                                        @endif
                                        <x-button
                                            class="btn-ghost btn-sm text-error"
                                            icon="o-trash"
                                            wire:click="confirmDelete('{{ $snapshot->id }}')"
                                        >
                                            {{ __('Delete') }}
                                        </x-button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center">
                                    @if($search || $statusFilter !== 'all')
                                        {{ __('No snapshots found matching your filters.') }}
                                    @else
                                        {{ __('No snapshots yet. Create a backup from the Database Servers page.') }}
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if($snapshots->hasPages())
                <div class="border-t border-base-300 px-4 py-3">
                    {{ $snapshots->links() }}
                </div>
            @endif
        </x-card>
    </div>

    <!-- Delete Confirmation Modal -->
    <x-delete-confirmation-modal
        :title="__('Delete Snapshot')"
        :message="__('Are you sure you want to delete this snapshot? The backup file will be permanently removed.')"
        onConfirm="delete"
    />
</div>
