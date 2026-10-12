<div>
    <x-index-header :title="__('Volumes')" :search="$search">
        @can('viewForm', App\Models\Volume::class)
            <x-button :label="__('Add Volume')" :link="route('volumes.create')" icon="o-plus" class="btn-primary btn-sm" wire:navigate />
        @endcan

        <x-slot:below>
            @if($showAwsDeprecationWarning)
                <x-alert class="alert-warning mb-4" icon="o-exclamation-triangle" dismissible>
                    {{ __('Deprecated AWS_* environment variables detected. S3 credentials are now configured per-volume in the UI. You can safely remove AWS_* variables from your environment.') }}
                </x-alert>
            @endif
        </x-slot:below>
    </x-index-header>

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
                <x-volume-type-badge :volume="$volume" class="badge-ghost badge-sm gap-1" />
            @endscope

            @scope('cell_config', $volume)
                @php $summary = $volume->getConfigSummary(); @endphp
                @foreach($summary as $label => $value)
                    <div class="text-sm {{ $loop->first ? '' : 'text-base-content/70' }}">
                        @if(count($summary) > 1){{ $label }}: @endif{{ $value }}
                    </div>
                @endforeach
            @endscope

            @scope('cell_usage', $volume)
                @php
                    $usedBytes = $volume->usedStorageBytes();
                    $limitBytes = $volume->maxStorageBytes();
                @endphp
                <div class="table-cell-primary {{ $limitBytes !== null && $usedBytes >= $limitBytes ? 'text-warning' : '' }}">
                    {{ \App\Support\Formatters::humanFileSize($usedBytes) }}
                </div>
                @if($limitBytes !== null)
                    <div class="text-sm text-base-content/70">{{ __('Limit') }}: {{ \App\Support\Formatters::bytesToGb($limitBytes) }} GB</div>
                @endif
            @endscope

            @scope('cell_created_at', $volume)
                <div class="table-cell-primary">{{ \App\Support\Formatters::humanDate($volume->created_at) }}</div>
                <div class="text-sm text-base-content/70">{{ $volume->created_at->diffForHumans() }}</div>
            @endscope

            @scope('actions', $volume)
                <div class="flex gap-2 justify-end">
                    @can('viewForm', $volume)
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
                            spinner
                            tooltip="{{ __('Delete') }}"
                            class="btn-ghost btn-sm text-error"
                        />
                    @endcan
                </div>
            @endscope
        </x-table>
    </x-card>

    <!-- DELETE CONFIRMATION MODAL -->
    <x-delete-confirmation-modal
        :title="__('Delete Volume')"
        :message="__('Are you sure you want to delete this volume? This action cannot be undone.')"
        onConfirm="delete"
        :showKeepFiles="$deleteSnapshotCount > 0"
        :snapshotCount="$deleteSnapshotCount"
    />
</div>
