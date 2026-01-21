<div>
    <!-- HEADER -->
    <x-header title="{{ __('Users') }}" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            <x-input placeholder="{{ __('Search...') }}" wire:model.live.debounce="search" clearable icon="o-magnifying-glass" />
        </x-slot:middle>
        <x-slot:actions>
            <x-button label="{{ __('Filters') }}" @click="$wire.drawer = true" responsive icon="o-funnel" class="btn-ghost" />
            @can('create', App\Models\User::class)
                <x-button label="{{ __('Add User') }}" link="{{ route('users.create') }}" icon="o-plus" class="btn-primary" wire:navigate />
            @endcan
        </x-slot:actions>
    </x-header>

    <!-- TABLE -->
    <x-card shadow>
        <x-table :headers="$headers" :rows="$users" :sort-by="$sortBy" with-pagination>
            <x-slot:empty>
                <div class="text-center text-base-content/50 py-8">
                    @if($search || $roleFilter !== '' || $statusFilter !== '')
                        {{ __('No users found matching your filters.') }}
                    @else
                        {{ __('No users yet.') }}
                    @endif
                </div>
            </x-slot:empty>

            @scope('cell_name', $user)
                <div class="flex items-center gap-2">
                    <div class="table-cell-primary">{{ $user->name }}</div>
                    @if($user->id === auth()->id())
                        <span class="text-xs text-base-content/50">{{ __('(You)') }}</span>
                    @endif
                    @if($user->isOAuthOnly())
                        <x-badge value="OAuth" class="badge-ghost badge-sm" />
                    @endif
                </div>
            @endscope

            @scope('cell_email', $user)
                {{ $user->email }}
            @endscope

            @scope('cell_role', $user)
                @php
                    $roleClass = match($user->role) {
                        'admin' => 'badge-primary',
                        'member' => 'badge-info',
                        'viewer' => 'badge-neutral',
                        default => 'badge-ghost',
                    };
                @endphp
                <x-badge :value="ucfirst($user->role)" class="{{ $roleClass }}" />
            @endscope

            @scope('cell_status', $user)
                @if($user->isActive())
                    <x-badge value="{{ __('Active') }}" class="badge-success" />
                @else
                    <x-badge value="{{ __('Pending') }}" class="badge-warning" />
                @endif
            @endscope

            @scope('cell_created_at', $user)
                <div class="table-cell-primary">{{ \App\Support\Formatters::humanDate($user->created_at) }}</div>
                <div class="text-sm text-base-content/70">{{ $user->created_at->diffForHumans() }}</div>
            @endscope

            @scope('actions', $user)
                <div class="flex gap-2 justify-end">
                    @can('copyInvitationLink', $user)
                        <x-button
                            icon="o-clipboard-document"
                            wire:click="copyInvitationLink({{ $user->id }})"
                            tooltip="{{ __('Copy Invitation Link') }}"
                            class="btn-ghost btn-sm text-info"
                        />
                    @endcan
                    @can('update', $user)
                        <x-button
                            icon="o-pencil"
                            link="{{ route('users.edit', $user) }}"
                            wire:navigate
                            tooltip="{{ __('Edit') }}"
                            class="btn-ghost btn-sm"
                        />
                    @endcan
                    @can('delete', $user)
                        <x-button
                            icon="o-trash"
                            wire:click="confirmDelete({{ $user->id }})"
                            tooltip="{{ __('Delete') }}"
                            class="btn-ghost btn-sm text-error"
                        />
                    @endcan
                </div>
            @endscope
        </x-table>
    </x-card>

    <!-- FILTER DRAWER -->
    <x-drawer wire:model="drawer" title="{{ __('Filters') }}" right separator with-close-button class="lg:w-1/3">
        <div class="grid gap-5">
            <x-input placeholder="{{ __('Search...') }}" wire:model.live.debounce="search" icon="o-magnifying-glass" @keydown.enter="$wire.drawer = false" />
            <x-select
                label="{{ __('Role') }}"
                placeholder="{{ __('All Roles') }}"
                placeholder-value=""
                wire:model.live="roleFilter"
                :options="$roleFilterOptions"
                icon="o-user-group"
            />
            <x-select
                label="{{ __('Status') }}"
                placeholder="{{ __('All Status') }}"
                placeholder-value=""
                wire:model.live="statusFilter"
                :options="$statusFilterOptions"
                icon="o-funnel"
            />
        </div>

        <x-slot:actions>
            <x-button label="{{ __('Reset') }}" icon="o-x-mark" wire:click="clear" spinner />
            <x-button label="{{ __('Done') }}" icon="o-check" class="btn-primary" @click="$wire.drawer = false" />
        </x-slot:actions>
    </x-drawer>

    <!-- DELETE CONFIRMATION MODAL -->
    <x-delete-confirmation-modal
        :title="__('Delete User')"
        :message="__('Are you sure you want to delete this user? This action cannot be undone.')"
        onConfirm="delete"
    />

    <!-- COPY INVITATION LINK MODAL -->
    <x-invitation-link-modal
        :title="__('Invitation Link')"
        :message="__('Copy this link and send it to the user so they can complete their registration.')"
        :doneLabel="__('Close')"
    />
</div>
