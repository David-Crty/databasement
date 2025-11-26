<div>
    <div class="mx-auto max-w-7xl">
        <!-- Header -->
        <div class="mb-6 flex items-center justify-between">
            <div>
                <x-header title="{{ __('Database Servers') }}" subtitle="{{ __('Manage your database server connections') }}" size="text-2xl" separator />
            </div>
            <x-button class="btn-primary" link="{{ route('database-servers.create') }}" icon="o-plus" wire:navigate>
                {{ __('Add Server') }}
            </x-button>
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
            <!-- Search Bar -->
            <div class="border-b border-base-300 p-4">
                <x-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by name, host, type, or description...') }}"
                    icon="o-magnifying-glass"
                    type="search"
                    clearable
                />
            </div>

            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="table-default w-full">
                    <thead>
                        <tr>
                            <th class="table-th">
                                <button wire:click="sortBy('name')" class="group table-th-sortable">
                                    {{ __('Name') }}
                                    <span class="opacity-50">
                                        @if($sortField === 'name')
                                            <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4" />
                                        @else
                                            <x-icon name="o-arrows-up-down" class="w-4 h-4 opacity-0 group-hover:opacity-50" />
                                        @endif
                                    </span>
                                </button>
                            </th>
                            <th class="table-th">
                                <button wire:click="sortBy('database_type')" class="group table-th-sortable">
                                    {{ __('Type') }}
                                    <span class="opacity-50">
                                        @if($sortField === 'database_type')
                                            <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4" />
                                        @else
                                            <x-icon name="o-arrows-up-down" class="w-4 h-4 opacity-0 group-hover:opacity-50" />
                                        @endif
                                    </span>
                                </button>
                            </th>
                            <th class="table-th">
                                <button wire:click="sortBy('host')" class="group table-th-sortable">
                                    {{ __('Host') }}
                                    <span class="opacity-50">
                                        @if($sortField === 'host')
                                            <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4" />
                                        @else
                                            <x-icon name="o-arrows-up-down" class="w-4 h-4 opacity-0 group-hover:opacity-50" />
                                        @endif
                                    </span>
                                </button>
                            </th>
                            <th class="table-th">
                                {{ __('Database') }}
                            </th>
                            <th class="table-th">
                                {{ __('Backup') }}
                            </th>
                            <th class="table-th">
                                <button wire:click="sortBy('created_at')" class="group table-th-sortable">
                                    {{ __('Created') }}
                                    <span class="opacity-50">
                                        @if($sortField === 'created_at')
                                            <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4" />
                                        @else
                                            <x-icon name="o-arrows-up-down" class="w-4 h-4 opacity-0 group-hover:opacity-50" />
                                        @endif
                                    </span>
                                </button>
                            </th>
                            <th class="table-th-right">
                                {{ __('Actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($servers as $server)
                            <tr>
                                <td>
                                    <div class="table-cell-primary">{{ $server->name }}</div>
                                    @if($server->description)
                                        <div>{{ Str::limit($server->description, 50) }}</div>
                                    @endif
                                </td>
                                <td>
                                    <x-table-badge>{{ $server->database_type }}</x-table-badge>
                                </td>
                                <td>
                                    {{ $server->host }}:{{ $server->port }}
                                </td>
                                <td>
                                    {{ $server->database_name ?? '-' }}
                                </td>
                                <td>
                                    <div class="table-cell-primary">{{ $server->backup->volume->name }}</div>
                                    <div class="capitalize">{{ $server->backup->recurrence }}</div>
                                </td>
                                <td>
                                    {{ $server->created_at->diffForHumans() }}
                                </td>
                                <td class="text-right">
                                    <div class="table-actions">
                                        @if($server->backup)
                                            <x-button
                                                class="btn-ghost btn-sm text-info"
                                                icon="o-arrow-down-tray"
                                                wire:click="runBackup('{{ $server->id }}')"
                                            >
                                                {{ __('Backup Now') }}
                                            </x-button>
                                        @endif
                                        <x-button
                                            class="btn-ghost btn-sm text-success"
                                            icon="o-arrow-up-tray"
                                            wire:click="confirmRestore('{{ $server->id }}')"
                                        >
                                            {{ __('Restore') }}
                                        </x-button>
                                        <x-button
                                            class="btn-ghost btn-sm"
                                            link="{{ route('database-servers.edit', $server) }}"
                                            icon="o-pencil"
                                            wire:navigate
                                        >
                                            {{ __('Edit') }}
                                        </x-button>
                                        <x-button
                                            class="btn-ghost btn-sm text-error"
                                            icon="o-trash"
                                            wire:click="confirmDelete('{{ $server->id }}')"
                                        >
                                            {{ __('Delete') }}
                                        </x-button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center">
                                    @if($search)
                                        {{ __('No database servers found matching your search.') }}
                                    @else
                                        {{ __('No database servers yet.') }}
                                        <a href="{{ route('database-servers.create') }}" class="link link-primary" wire:navigate>
                                            {{ __('Create your first one.') }}
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if($servers->hasPages())
                <div class="border-t border-base-300 px-4 py-3">
                    {{ $servers->links() }}
                </div>
            @endif
        </x-card>
    </div>

    <!-- Delete Confirmation Modal -->
    <x-delete-confirmation-modal
        :title="__('Delete Database Server')"
        :message="__('Are you sure you want to delete this database server? This action cannot be undone.')"
        onConfirm="delete"
    />

    <!-- Restore Modal -->
    <livewire:database-server.restore-modal />
</div>
