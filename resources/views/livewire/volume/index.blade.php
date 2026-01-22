<div>
    <!-- HEADER with search (Desktop) -->
    <x-header title="{{ __('Volumes') }}" separator progress-indicator>
        <x-slot:actions>
            <div class="hidden sm:flex items-center gap-2">
                <x-input
                    placeholder="{{ __('Search...') }}"
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="!input-sm w-48"
                />
                @if($search)
                    <x-button
                        icon="o-x-mark"
                        wire:click="clear"
                        spinner
                        class="btn-ghost btn-sm"
                        tooltip="{{ __('Clear search') }}"
                    />
                @endif
            </div>
            @can('create', App\Models\Volume::class)
                <x-button label="{{ __('Add Volume') }}" link="{{ route('volumes.create') }}" icon="o-plus" class="btn-primary btn-sm" wire:navigate />
            @endcan
        </x-slot:actions>
    </x-header>

    <!-- SEARCH (Mobile) -->
    <div class="sm:hidden mb-4">
        <x-input
            placeholder="{{ __('Search...') }}"
            wire:model.live.debounce="search"
            clearable
            icon="o-magnifying-glass"
        />
    </div>

    <!-- TABLE -->
    <x-card shadow>
        <x-table :headers="$headers" :rows="$volumes" :sort-by="$sortBy" with-pagination>
            <x-slot:empty>
                <div class="text-center text-base-content/50 py-8">
                    @if($search)
                        {{ __('No volumes found matching your search.') }}
                    @else
                        {{ __('No volumes yet.') }}
                        <a href="{{ route('volumes.create') }}" class="link link-primary" wire:navigate>
                            {{ __('Create your first one.') }}
                        </a>
                    @endif
                </div>
            </x-slot:empty>

            @scope('cell_name', $volume)
                <div class="table-cell-primary">{{ $volume->name }}</div>
            @endscope

            @scope('cell_type', $volume)
                <x-badge :value="$volume->type" />
            @endscope

            @scope('cell_config', $volume)
                @if($volume->type === 's3')
                    <div class="text-sm">Bucket: {{ $volume->config['bucket'] }}</div>
                    @if(!empty($volume->config['prefix']))
                        <div class="text-sm text-base-content/70">Prefix: {{ $volume->config['prefix'] }}</div>
                    @endif
                @elseif($volume->type === 'local')
                    <div class="text-sm">{{ $volume->config['path'] }}</div>
                @endif
            @endscope

            @scope('cell_created_at', $volume)
                <div class="table-cell-primary">{{ \App\Support\Formatters::humanDate($volume->created_at) }}</div>
                <div class="text-sm text-base-content/70">{{ $volume->created_at->diffForHumans() }}</div>
            @endscope

            @scope('actions', $volume)
                <div class="flex gap-2 justify-end">
                    @can('update', $volume)
                        <x-button
                            icon="o-pencil"
                            link="{{ route('volumes.edit', $volume) }}"
                            wire:navigate
                            tooltip="{{ __('Edit') }}"
                            class="btn-ghost btn-sm"
                        />
                    @endcan
                    @can('delete', $volume)
                        <x-button
                            icon="o-trash"
                            wire:click="confirmDelete('{{ $volume->id }}')"
                            tooltip="{{ __('Delete') }}"
                            class="btn-ghost btn-sm text-error"
                        />
                    @endcan
                </div>
            @endscope
        </x-table>
    </x-card>

    <!-- DELETE CONFIRMATION MODAL -->
    <x-modal wire:model="showDeleteModal" :title="__('Delete Volume')" class="backdrop-blur">
        <p>{{ __('Are you sure you want to delete this volume? This action cannot be undone.') }}</p>

        @if($deleteSnapshotCount > 0)
            <x-alert icon="o-exclamation-triangle" class="alert-warning mt-4">
                {{ trans_choice(':count snapshot will also be deleted.|:count snapshots will also be deleted.', $deleteSnapshotCount, ['count' => $deleteSnapshotCount]) }}
            </x-alert>
        @endif

        <x-slot:actions>
            <x-button label="{{ __('Cancel') }}" @click="$wire.showDeleteModal = false" />
            <x-button label="{{ __('Delete') }}" class="btn-error" wire:click="delete" />
        </x-slot:actions>
    </x-modal>
</div>
