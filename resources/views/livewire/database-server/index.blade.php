<div>
    <div class="mx-auto max-w-7xl">
        <!-- Header -->
        <div class="mb-6 flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ __('Database Servers') }}</flux:heading>
                <flux:subheading>{{ __('Manage your database server connections') }}</flux:subheading>
            </div>
            <flux:button variant="primary" :href="route('database-servers.create')" icon="plus" wire:navigate>
                {{ __('Add Server') }}
            </flux:button>
        </div>

        @if (session('status'))
            <x-banner variant="success" dismissible class="mb-6">
                {{ session('status') }}
            </x-banner>
        @endif

        <x-card :padding="false">
            <!-- Search Bar -->
            <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by name, host, type, or description...') }}"
                    icon="magnifying-glass"
                    type="search"
                />
            </div>

            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left">
                                <button wire:click="sortBy('name')" class="group flex items-center gap-1 text-xs font-semibold uppercase tracking-wider text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100">
                                    {{ __('Name') }}
                                    <span class="text-zinc-400">
                                        @if($sortField === 'name')
                                            @if($sortDirection === 'asc')
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                                </svg>
                                            @else
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                                </svg>
                                            @endif
                                        @else
                                            <svg class="h-4 w-4 opacity-0 group-hover:opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                            </svg>
                                        @endif
                                    </span>
                                </button>
                            </th>
                            <th class="px-4 py-3 text-left">
                                <button wire:click="sortBy('database_type')" class="group flex items-center gap-1 text-xs font-semibold uppercase tracking-wider text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100">
                                    {{ __('Type') }}
                                    <span class="text-zinc-400">
                                        @if($sortField === 'database_type')
                                            @if($sortDirection === 'asc')
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                                </svg>
                                            @else
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                                </svg>
                                            @endif
                                        @else
                                            <svg class="h-4 w-4 opacity-0 group-hover:opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                            </svg>
                                        @endif
                                    </span>
                                </button>
                            </th>
                            <th class="px-4 py-3 text-left">
                                <button wire:click="sortBy('host')" class="group flex items-center gap-1 text-xs font-semibold uppercase tracking-wider text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100">
                                    {{ __('Host') }}
                                    <span class="text-zinc-400">
                                        @if($sortField === 'host')
                                            @if($sortDirection === 'asc')
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                                </svg>
                                            @else
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                                </svg>
                                            @endif
                                        @else
                                            <svg class="h-4 w-4 opacity-0 group-hover:opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                            </svg>
                                        @endif
                                    </span>
                                </button>
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">
                                {{ __('Database') }}
                            </th>
                            <th class="px-4 py-3 text-left">
                                <button wire:click="sortBy('created_at')" class="group flex items-center gap-1 text-xs font-semibold uppercase tracking-wider text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100">
                                    {{ __('Created') }}
                                    <span class="text-zinc-400">
                                        @if($sortField === 'created_at')
                                            @if($sortDirection === 'asc')
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                                </svg>
                                            @else
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                                </svg>
                                            @endif
                                        @else
                                            <svg class="h-4 w-4 opacity-0 group-hover:opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                            </svg>
                                        @endif
                                    </span>
                                </button>
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">
                                {{ __('Actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse($servers as $server)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="px-4 py-4">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $server->name }}</div>
                                    @if($server->description)
                                        <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ Str::limit($server->description, 50) }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-4">
                                    <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold uppercase bg-zinc-100 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-200">
                                        {{ $server->database_type }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-sm text-zinc-700 dark:text-zinc-300">
                                    {{ $server->host }}:{{ $server->port }}
                                </td>
                                <td class="px-4 py-4 text-sm text-zinc-700 dark:text-zinc-300">
                                    {{ $server->database_name ?? '-' }}
                                </td>
                                <td class="px-4 py-4 text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $server->created_at->diffForHumans() }}
                                </td>
                                <td class="px-4 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <flux:button size="sm" variant="ghost" :href="route('database-servers.edit', $server)" icon="pencil" wire:navigate>
                                            {{ __('Edit') }}
                                        </flux:button>
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete('{{ $server->id }}')" class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                            {{ __('Delete') }}
                                        </flux:button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                    @if($search)
                                        {{ __('No database servers found matching your search.') }}
                                    @else
                                        {{ __('No database servers yet.') }}
                                        <a href="{{ route('database-servers.create') }}" class="text-zinc-900 underline dark:text-zinc-100" wire:navigate>
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
                <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    {{ $servers->links() }}
                </div>
            @endif
        </x-card>
    </div>

    <!-- Delete Confirmation Modal -->
    @if($deleteId)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
                <!-- Background overlay -->
                <div class="fixed inset-0 bg-zinc-500 bg-opacity-75 transition-opacity dark:bg-zinc-900 dark:bg-opacity-75" wire:click="cancelDelete"></div>

                <!-- Modal panel -->
                <div class="inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all dark:bg-zinc-800 sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                    <div class="bg-white px-4 pb-4 pt-5 dark:bg-zinc-800 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/20 sm:mx-0 sm:h-10 sm:w-10">
                                <svg class="h-6 w-6 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                </svg>
                            </div>
                            <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                                <h3 class="text-lg font-semibold leading-6 text-zinc-900 dark:text-zinc-100" id="modal-title">
                                    {{ __('Delete Database Server') }}
                                </h3>
                                <div class="mt-2">
                                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ __('Are you sure you want to delete this database server? This action cannot be undone.') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-zinc-50 px-4 py-3 dark:bg-zinc-900 sm:flex sm:flex-row-reverse sm:px-6">
                        <flux:button variant="primary" wire:click="delete" class="w-full sm:ml-3 sm:w-auto bg-red-600 hover:bg-red-700 dark:bg-red-600 dark:hover:bg-red-700">
                            {{ __('Delete') }}
                        </flux:button>
                        <flux:button variant="ghost" wire:click="cancelDelete" class="mt-3 w-full sm:mt-0 sm:w-auto">
                            {{ __('Cancel') }}
                        </flux:button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
