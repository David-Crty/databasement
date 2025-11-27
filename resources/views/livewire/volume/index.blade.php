<div>
    <!-- HEADER -->
    <x-header title="{{ __('Volumes') }}" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            <x-input placeholder="{{ __('Search...') }}" wire:model.live.debounce="search" clearable icon="o-magnifying-glass" />
        </x-slot:middle>
        <x-slot:actions>
            <x-button label="{{ __('Filters') }}" @click="$wire.drawer = true" responsive icon="o-funnel" class="btn-ghost" />
            <x-button label="{{ __('Add Volume') }}" link="{{ route('volumes.create') }}" icon="o-plus" class="btn-primary" wire:navigate />
        </x-slot:actions>
    </x-header>

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
                {{ $volume->created_at->diffForHumans() }}
            @endscope

            @scope('actions', $volume)
                <div class="flex gap-2 justify-end">
                    <x-button
                        icon="o-pencil"
                        link="{{ route('volumes.edit', $volume) }}"
                        wire:navigate
                        tooltip="{{ __('Edit') }}"
                        class="btn-ghost btn-sm"
                    />
                    <x-button
                        icon="o-trash"
                        wire:click="confirmDelete('{{ $volume->id }}')"
                        tooltip="{{ __('Delete') }}"
                        class="btn-ghost btn-sm text-error"
                    />
                </div>
            @endscope
        </x-table>
    </x-card>

    <!-- FILTER DRAWER -->
    <x-drawer wire:model="drawer" title="{{ __('Filters') }}" right separator with-close-button class="lg:w-1/3">
        <x-input placeholder="{{ __('Search...') }}" wire:model.live.debounce="search" icon="o-magnifying-glass" @keydown.enter="$wire.drawer = false" />

        <x-slot:actions>
            <x-button label="{{ __('Reset') }}" icon="o-x-mark" wire:click="clear" spinner />
            <x-button label="{{ __('Done') }}" icon="o-check" class="btn-primary" @click="$wire.drawer = false" />
        </x-slot:actions>
    </x-drawer>

    <!-- DELETE CONFIRMATION MODAL -->
    <x-delete-confirmation-modal
        :title="__('Delete Volume')"
        :message="__('Are you sure you want to delete this volume? This action cannot be undone.')"
        onConfirm="delete"
    />
</div>
