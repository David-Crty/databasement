<div>
    <div class="mx-auto max-w-7xl">
        <!-- Header -->
        <div class="mb-6 flex items-center justify-between">
            <div>
                <x-header title="{{ __('Volumes') }}" subtitle="{{ __('Manage your backup storage volumes') }}" size="text-2xl" separator />
            </div>
            <x-button class="btn-primary" link="{{ route('volumes.create') }}" icon="o-plus" wire:navigate>
                {{ __('Add Volume') }}
            </x-button>
        </div>

        @if (session('status'))
            <x-alert class="alert-success mb-6" icon="o-check-circle" dismissible>
                {{ session('status') }}
            </x-alert>
        @endif

        <x-card :padding="false">
            <!-- Search Bar -->
            <div class="border-b border-base-300 p-4">
                <x-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by name or type...') }}"
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
                                <button wire:click="sortBy('type')" class="group table-th-sortable">
                                    {{ __('Type') }}
                                    <span class="opacity-50">
                                        @if($sortField === 'type')
                                            <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4" />
                                        @else
                                            <x-icon name="o-arrows-up-down" class="w-4 h-4 opacity-0 group-hover:opacity-50" />
                                        @endif
                                    </span>
                                </button>
                            </th>
                            <th class="table-th">
                                {{ __('Configuration') }}
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
                        @forelse($volumes as $volume)
                            <tr>
                                <td>
                                    <div class="table-cell-primary">{{ $volume->name }}</div>
                                </td>
                                <td>
                                    <x-table-badge>{{ $volume->type }}</x-table-badge>
                                </td>
                                <td>
                                    @if($volume->type === 's3')
                                        <div>Bucket: {{ $volume->config['bucket'] }}</div>
                                        @if(!empty($volume->config['prefix']))
                                            <div>Prefix: {{ $volume->config['prefix'] }}</div>
                                        @endif
                                    @elseif($volume->type === 'local')
                                        <div>{{ $volume->config['path'] }}</div>
                                    @endif
                                </td>
                                <td>
                                    {{ $volume->created_at->diffForHumans() }}
                                </td>
                                <td class="text-right">
                                    <div class="table-actions">
                                        <x-button
                                            class="btn-ghost btn-sm"
                                            link="{{ route('volumes.edit', $volume) }}"
                                            icon="o-pencil"
                                            wire:navigate
                                        >
                                            {{ __('Edit') }}
                                        </x-button>
                                        <x-button
                                            class="btn-ghost btn-sm text-error"
                                            icon="o-trash"
                                            wire:click="confirmDelete('{{ $volume->id }}')"
                                        >
                                            {{ __('Delete') }}
                                        </x-button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center">
                                    @if($search)
                                        {{ __('No volumes found matching your search.') }}
                                    @else
                                        {{ __('No volumes yet.') }}
                                        <a href="{{ route('volumes.create') }}" class="link link-primary" wire:navigate>
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
            @if($volumes->hasPages())
                <div class="border-t border-base-300 px-4 py-3">
                    {{ $volumes->links() }}
                </div>
            @endif
        </x-card>
    </div>

    <!-- Delete Confirmation Modal -->
    <x-delete-confirmation-modal
        :title="__('Delete Volume')"
        :message="__('Are you sure you want to delete this volume? This action cannot be undone.')"
        onConfirm="delete"
    />
</div>
