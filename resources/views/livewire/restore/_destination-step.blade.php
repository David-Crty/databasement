@php
    use App\Enums\DatabaseType;
    use App\Services\Backup\Databases\PostgresqlDatabase;
@endphp
{{--
    Shared "destination" step for the restore modals: choose the target server
    (unless it is locked), the destination database name (type-ahead), and the
    per-restore options. The host modal renders its own restore summary.

    Requires the host component to use App\Livewire\Concerns\InteractsWithTargetDatabases
    (which provides the bound $targetServerId, $schemaName, $forceDatabase and
    $ownerUser state) and to expose $this->targetServerOptions plus a
    $this->targetServer accessor.

    Params:
      $targetLocked (bool) - when true the target is fixed; hide the select
      $snapshotPreservesPrivileges (bool, optional) - when true the snapshot was
          dumped with ownership/privilege information, so the restore sets the
          owners of the objects itself and the post-restore option narrows to
          the database's own owner (which no dump carries)
--}}
@php
    $type = $this->targetServer?->database_type;
    $isSqlite = $type === DatabaseType::SQLITE;
    $preservesPrivileges = $snapshotPreservesPrivileges ?? false;
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
            wire:model.live.debounce.300ms="ownerUser"
            :label="$preservesPrivileges
                ? __('Set database owner after restore')
                : __('Transfer database ownership to user after restore')"
            :placeholder="__('PostgreSQL username (leave empty to skip)')"
        />

        {{-- Named owner only: an empty field skips the transfer altogether. --}}
        @php
            $owner = trim($ownerUser);
            $ownershipStatements = $owner === '' ? [] : PostgresqlDatabase::ownershipStatements(
                $schemaName,
                $owner,
                (string) $this->targetServer?->username,
                $preservesPrivileges,
            );
        @endphp

        @if($ownershipStatements)
            <div class="fieldset-label mt-1 block text-xs">
                {{ __('This SQL will be run after the restore:') }}
                <pre class="bg-base-200 rounded-box mt-1 overflow-x-auto p-3"><code class="select-all">{{ implode(PHP_EOL, $ownershipStatements) }}</code></pre>
            </div>
        @endif

        <div class="fieldset-label mt-1 text-xs">
            {{ __('Restoring over existing objects requires ownership of them, not just privileges.') }}
            <a href="https://david-crty.github.io/databasement/user-guide/database-servers#postgresql"
               target="_blank"
               class="link link-primary underline-offset-2">{{ __('PostgreSQL permissions') }}</a>
        </div>
    @endif

    @if(in_array($type, [DatabaseType::MYSQL, DatabaseType::POSTGRESQL], true))
        <x-checkbox
            wire:model="forceDatabase"
            :label="__('Drop and recreate database before restore')"
            :hint="__('Not usually needed — dumps already include per-table DROP/CREATE statements. Use this only if you need a completely clean database (e.g. to remove tables not in the snapshot).')"
        />
    @endif
@endif

