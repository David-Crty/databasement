<div>
    <x-card class="mt-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="font-semibold text-base">{{ __('Server Access') }}</h3>
                <p class="text-sm text-base-content/60 mt-0.5">
                    @if($user->isAdmin() || $user->isMember())
                        {{ __('This user has full access to all servers based on their role.') }}
                    @else
                        {{ __('Restrict this user to specific servers and databases. Without any grants, viewers see all servers.') }}
                    @endif
                </p>
            </div>
            @if(!$user->isAdmin() && !$user->isMember())
                @can('update', $user)
                    <x-button
                        :label="__('Grant Access')"
                        icon="o-plus"
                        class="btn-primary btn-sm"
                        wire:click="openGrantModal"
                    />
                @endcan
            @endif
        </div>

        @if($user->isAdmin() || $user->isMember())
            {{-- No grants UI for privileged roles --}}
        @elseif($accesses->isEmpty())
            <div class="text-sm text-base-content/50 py-4 text-center">
                {{ __('No server access grants. This user can view all servers as a Viewer.') }}
            </div>
        @else
            <div class="space-y-2">
                @foreach($accesses as $access)
                    <div class="flex items-start justify-between gap-4 p-3 rounded-lg bg-base-200">
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-sm">{{ $access->databaseServer->name }}</div>
                            <div class="text-xs text-base-content/60 mt-0.5">{{ $access->databaseServer->host }}</div>

                            @if($access->allowed_databases)
                                <div class="flex flex-wrap gap-1 mt-1.5">
                                    @foreach($access->allowed_databases as $db)
                                        <x-badge :value="$db" class="badge-outline badge-sm" />
                                    @endforeach
                                </div>
                            @else
                                <div class="text-xs text-base-content/50 mt-1">{{ __('All databases') }}</div>
                            @endif

                            <div class="flex gap-3 mt-1.5">
                                @if($access->can_download)
                                    <span class="text-xs text-success flex items-center gap-1">
                                        <x-icon name="o-arrow-down-tray" class="w-3 h-3" /> {{ __('Download') }}
                                    </span>
                                @endif
                                @if($access->can_backup)
                                    <span class="text-xs text-warning flex items-center gap-1">
                                        <x-icon name="o-circle-stack" class="w-3 h-3" /> {{ __('Backup') }}
                                    </span>
                                @endif
                                @if($access->can_restore)
                                    <span class="text-xs text-info flex items-center gap-1">
                                        <x-icon name="o-arrow-path" class="w-3 h-3" /> {{ __('Restore') }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        @can('update', $user)
                            <div class="flex gap-1">
                                <x-button
                                    icon="o-pencil"
                                    wire:click="openGrantModal({{ $access->id }})"
                                    :tooltip="__('Edit Access')"
                                    class="btn-ghost btn-sm"
                                />
                                <x-button
                                    icon="o-trash"
                                    wire:click="revokeAccess({{ $access->id }})"
                                    :tooltip="__('Revoke Access')"
                                    class="btn-ghost btn-sm text-error"
                                    wire:confirm="{{ __('Revoke access to this server?') }}"
                                />
                            </div>
                        @endcan
                    </div>
                @endforeach
            </div>
        @endif
    </x-card>

    <!-- GRANT ACCESS MODAL -->
    <x-modal wire:model="showGrantModal" :title="__('Grant Server Access')" class="backdrop-blur">
        <div class="space-y-4">
            <x-select
                wire:model.live="selectedServerId"
                :label="__('Database Server')"
                :options="$availableServers"
                :placeholder="__('Select a server…')"
                required
            />

            <div>
                <label class="label label-text font-semibold mb-1">{{ __('Allowed Databases') }}</label>
                <p class="text-xs text-base-content/60 mb-2">{{ __('Leave empty to allow access to all databases on this server.') }}</p>

                @if(count($allowedDatabases) > 0)
                    <div class="flex flex-wrap gap-1.5 mb-2">
                        @foreach($allowedDatabases as $db)
                            <span class="badge badge-outline gap-1 text-sm">
                                {{ $db }}
                                <button
                                    type="button"
                                    wire:click="removeDatabase('{{ $db }}')"
                                    class="ml-0.5 hover:text-error leading-none"
                                >×</button>
                            </span>
                        @endforeach
                    </div>
                @endif

                <div class="flex gap-2">
                    <x-input
                        wire:model.live="databaseSearch"
                        :placeholder="__('Search or type a database name…')"
                        class="flex-1"
                        wire:keydown.enter.prevent="addSearchedDatabase"
                        :disabled="$selectedServerId === ''"
                    />
                    <x-button
                        :label="__('Add')"
                        wire:click="addSearchedDatabase"
                        class="btn-ghost btn-sm"
                        :disabled="$databaseSearch === ''"
                    />
                </div>

                @if($selectedServerId !== '' && count($this->knownDatabases) > 0)
                    <div class="mt-2 border border-base-300 rounded-lg overflow-hidden">
                        <div class="text-xs text-base-content/50 px-3 py-1.5 bg-base-200">
                            {{ __('Known databases from past snapshots') }}
                        </div>
                        @foreach($this->knownDatabases as $db)
                            <button
                                type="button"
                                wire:click="addDatabase('{{ $db }}')"
                                class="w-full text-left px-3 py-2 text-sm hover:bg-base-200 flex items-center gap-2 border-t border-base-300 first:border-0"
                            >
                                <x-icon name="o-circle-stack" class="w-3.5 h-3.5 text-base-content/40" />
                                {{ $db }}
                            </button>
                        @endforeach
                    </div>
                @elseif($selectedServerId !== '' && $databaseSearch !== '' && count($this->knownDatabases) === 0)
                    <p class="text-xs text-base-content/50 mt-1.5">{{ __('No matching databases found in snapshot history. Press Add or Enter to add it anyway.') }}</p>
                @elseif($selectedServerId !== '')
                    <p class="text-xs text-base-content/50 mt-1.5">{{ __('Start typing to search databases from past snapshots.') }}</p>
                @endif
            </div>

            <div class="space-y-2">
                <label class="label label-text font-semibold">{{ __('Permissions') }}</label>
                <x-checkbox wire:model="canDownload" :label="__('Allow downloading snapshots')" />
                <x-checkbox wire:model="canBackup" :label="__('Allow triggering manual backups')" />
                <x-checkbox wire:model="canRestore" :label="__('Allow restoring snapshots')" />
            </div>
        </div>

        <x-slot:actions>
            <x-button :label="__('Cancel')" wire:click="$set('showGrantModal', false)" />
            <x-button :label="__('Grant Access')" class="btn-primary" wire:click="grantAccess" spinner="grantAccess" />
        </x-slot:actions>
    </x-modal>
</div>
