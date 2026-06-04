@php use App\Enums\DatabaseType; @endphp
{{--
    Shared "destination" step for the restore modals: choose the target server
    (unless it is locked), the destination database name (type-ahead), and the
    per-restore options. Optionally renders a restore summary when a concrete
    snapshot is in context.

    Requires the host component to use App\Livewire\Concerns\InteractsWithTargetDatabases
    (which provides the bound $targetServerId, $schemaName, $forceDatabase and
    $ownerUser state) and to expose $this->targetServerOptions plus a
    $this->targetServer accessor.

    Params:
      $targetLocked (bool)        - when true the target is fixed; hide the select
      $snapshot (?Snapshot)       - when set, show a restore summary for it
--}}
@php
    $type = $this->targetServer?->database_type;
    $isSqlite = $type === DatabaseType::SQLITE;
@endphp

@unless($targetLocked)
    <x-select
        :label="__('Target server')"
        wire:model.live="targetServerId"
        :options="$this->targetServerOptions"
        :placeholder="__('Select a target server')"
        placeholder-value=""
    />
@endunless

@if($targetLocked || $this->targetServer)
    @include('livewire.restore._destination-autocomplete', [
        'label' => $isSqlite ? __('Destination database path') : __('Destination database name'),
        'placeholder' => $isSqlite ? '/data/staging.sqlite' : __('Type or select database name...'),
    ])

    @if(in_array($schemaName, $existingDatabases, true))
        <x-alert class="alert-warning" icon="o-exclamation-triangle">
            {{ __('The database') }}
            <x-badge class="badge-error badge-dash" :value="$schemaName"/> {{ __('already exists.') }}
            <br>
            {{ __('It will be overwritten if you continue.') }}
        </x-alert>
    @endif

    @if($type === DatabaseType::POSTGRESQL)
        <x-input
            wire:model="ownerUser"
            :label="__('Transfer database ownership to user after restore')"
            :placeholder="__('PostgreSQL username (leave empty to skip)')"
            :hint="__('Transfers ownership of the database and all its objects (tables, sequences, functions, schemas) to this user. Useful when the restore user differs from the application user.')"
        />
    @endif

    @if(in_array($type, [DatabaseType::MYSQL, DatabaseType::POSTGRESQL], true))
        <x-checkbox
            wire:model="forceDatabase"
            :label="__('Drop and recreate database before restore')"
            :hint="__('Not usually needed — dumps already include per-table DROP/CREATE statements. Use this only if you need a completely clean database (e.g. to remove tables not in the snapshot).')"
        />
    @endif
@endif

@if($snapshot)
    <div class="p-4 border rounded-lg bg-base-200 border-base-300">
        <div class="text-sm font-semibold mb-2">{{ __('Restore Summary') }}</div>
        <div class="text-sm opacity-70 space-y-1">
            <div><strong>{{ __('Source:') }}</strong>
                {{ $snapshot->databaseServer->name }} &bull; {{ $snapshot->database_name }}
            </div>
            <div>
                <strong>{{ __('Snapshot:') }}</strong> {{ \App\Support\Formatters::humanDate($snapshot->created_at) }}
            </div>
            <div><strong>{{ __('Target:') }}</strong>
                {{ $this->targetServer?->name }} &bull; {{ $schemaName ?: __('(enter name)') }}</div>
            <div>
                <strong>{{ __('Size:') }}</strong> {{ $snapshot->getHumanFileSize() }}
            </div>
        </div>
    </div>
@endif
