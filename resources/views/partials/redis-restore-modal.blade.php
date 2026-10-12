<x-modal wire:model="showRedisRestoreModal" :title="__('Restore Redis / Valkey Snapshot')" class="backdrop-blur">
    <div class="space-y-4">
        <x-alert class="alert-info" icon="o-information-circle">
            <div>
                <span class="font-bold">{{ __('Manual Restore Required') }}</span>
                <p class="text-sm mt-1">
                    {{ __('Automated restore is not supported for Redis/Valkey. RDB snapshots must be restored manually.') }}
                </p>
            </div>
        </x-alert>

        <div class="p-4 border rounded-lg bg-base-200 border-base-300 space-y-3">
            <div class="text-sm font-semibold">{{ __('How to Restore an RDB Snapshot') }}</div>
            <ol class="list-decimal list-inside text-sm space-y-2 opacity-80">
                <li>{{ __('Download the snapshot archive (.rdb.gz) from your storage volume.') }}</li>
                <li>{{ __('Extract the RDB file from the archive (e.g., gunzip snapshot.rdb.gz).') }}</li>
                <li>{{ __('Stop the Redis/Valkey server.') }}</li>
                <li>{{ __('Copy the RDB file to the Redis data directory, replacing dump.rdb.') }}</li>
                <li>{{ __('Set correct file permissions (e.g., chown redis:redis dump.rdb).') }}</li>
                <li>{{ __('Restart the Redis/Valkey server.') }}</li>
            </ol>
        </div>

        @if ($serverId)
            <a href="{{ route('snapshots.index', ['serverFilter' => $serverId]) }}"
               class="btn btn-sm btn-outline gap-2" wire:navigate>
                <x-icon name="o-arrow-down-tray" class="w-4 h-4" />
                {{ __('View Backup Snapshots') }}
            </a>
        @endif
    </div>

    <x-slot:actions>
        <x-button :label="__('Close')" @click="$wire.showRedisRestoreModal = false" />
    </x-slot:actions>
</x-modal>
