<div>
    <x-modal wire:model="showModal" :title="__('Servers across organizations')"
             :subtitle="__('Every database server and its latest backup, regardless of organization.')"
             box-class="max-w-3xl w-11/12" class="backdrop-blur">
        @if ($showModal)
            <x-input :placeholder="__('Search...')" wire:model.live.debounce="search" clearable
                     icon="o-magnifying-glass" class="mb-4" />

            <x-table :headers="$headers" :rows="$this->servers" with-pagination>
                <x-slot:empty>
                    <div class="text-center text-base-content/50 py-8">
                        @if ($search)
                            {{ __('No database servers found matching your search.') }}
                        @else
                            {{ __('No database servers yet.') }}
                        @endif
                    </div>
                </x-slot:empty>

                @scope('cell_name', $server)
                <div class="flex items-center gap-2">
                    <x-icon :name="$server->database_type->icon()" class="w-5 h-5 shrink-0" />
                    <span class="font-medium">{{ $server->name }}</span>
                </div>
                @endscope

                @scope('cell_organization', $server)
                {{ $server->organization->name }}
                @endscope

                @scope('cell_latest_backup', $server)
                <div class="flex items-center gap-2">
                    <x-job-status-indicator :status="$server->latest_backup_status ?? 'never'" />
                    @if ($server->latest_backup_at)
                        <span class="text-xs text-base-content/60">{{ $server->latest_backup_at->diffForHumans() }}</span>
                    @endif
                </div>
                @endscope

                @scope('cell_actions', $server)
                <x-button icon="o-arrow-top-right-on-square" class="btn-ghost btn-xs"
                          wire:click="openServer('{{ $server->id }}')" spinner
                          :tooltip-left="__('Open in its organization')" />
                @endscope
            </x-table>
        @endif
    </x-modal>
</div>
