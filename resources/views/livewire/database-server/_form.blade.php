@props(['form', 'submitLabel' => 'Save', 'cancelRoute' => 'database-servers.index', 'isEdit' => false])

@php
use App\Enums\DatabaseType;
@endphp

<x-form wire:submit="save" class="space-y-6">
    <!-- Section 1: Basic Information -->
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-3 sm:p-8">
            <div class="flex items-center gap-3 mb-4">
                <span class="badge badge-primary badge-lg font-bold">1</span>
                <h3 class="card-title text-lg">{{ __('Basic Information') }}</h3>
            </div>

            <div class="space-y-4">
                <x-input
                    wire:model="form.name"
                    label="{{ __('Server Name') }}"
                    placeholder="{{ __('e.g., Production MySQL Server') }}"
                    hint="{{ __('A friendly name to identify this server') }}"
                    type="text"
                    required
                />

                <x-textarea
                    wire:model="form.description"
                    label="{{ __('Description') }}"
                    placeholder="{{ __('Brief description of this database server') }}"
                    :hint="__('Notes for your team about this server\'s purpose')"
                    rows="2"
                />

                @php $agentOptions = $form->getAgentOptions(); @endphp
                @if(count($agentOptions) > 0 || $form->hasAgent())
                    <div class="border border-base-300 rounded-lg bg-base-200">
                        <!-- Toggle Header -->
                        <label class="flex items-start gap-3 p-4 cursor-pointer select-none">
                            <x-toggle
                                wire:model.live="form.use_agent"
                                class="toggle-primary"
                            />
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-medium">{{ __('Use a remote agent') }}</span>
                                    <span class="badge badge-ghost badge-sm text-base-content/50 font-normal">{{ __('Optional') }}</span>
                                </div>
                                <p class="text-xs text-base-content/50 mt-0.5 leading-relaxed">
                                    {{ __('Route backups through an agent installed on a server inside your private network or behind a firewall.') }}
                                </p>
                            </div>
                        </label>

                        <!-- Agent Selection (shown only when enabled) -->
                        @if($form->use_agent)
                            <div class="border-t border-base-300 bg-base-100 p-4 rounded-b-lg space-y-3">
                                <x-select
                                    wire:model.live="form.agent_id"
                                    :label="__('Agent')"
                                    :options="$agentOptions"
                                    :placeholder="__('Select an agent')"
                                    placeholder-value=""
                                />

                                @if($form->hasAgent())
                                    @php $selectedAgent = $form->getSelectedAgent(); @endphp
                                    @if($selectedAgent)
                                        <div class="flex items-center gap-2 text-sm">
                                            @if($selectedAgent->isOnline())
                                                <span class="badge badge-success badge-sm gap-1">
                                                    <span class="w-2 h-2 rounded-full bg-success animate-pulse"></span>
                                                    {{ __('Online') }}
                                                </span>
                                                <span class="text-base-content/70">{{ __('Last heartbeat :time', ['time' => $selectedAgent->last_heartbeat_at->diffForHumans()]) }}</span>
                                            @elseif($selectedAgent->last_heartbeat_at)
                                                <span class="badge badge-warning badge-sm gap-1">
                                                    <span class="w-2 h-2 rounded-full bg-warning"></span>
                                                    {{ __('Offline') }}
                                                </span>
                                                <span class="text-base-content/70">{{ __('Last heartbeat :time', ['time' => $selectedAgent->last_heartbeat_at->diffForHumans()]) }}</span>
                                            @else
                                                <span class="badge badge-ghost badge-sm">{{ __('Never connected') }}</span>
                                            @endif
                                        </div>
                                    @endif
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Section 2: Connection Details -->
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-3 sm:p-8">
            <div class="flex items-center gap-3 mb-4">
                <span class="badge badge-primary badge-lg font-bold">2</span>
                <h3 class="card-title text-lg">{{ __('Connection Details') }}</h3>
            </div>

            <div class="space-y-4">
                <!-- Database Type Selection -->
                <div>
                    <label class="label label-text font-semibold mb-2">{{ __('Database Type') }}</label>
                    <x-radio-card-group class="grid-cols-2 sm:grid-cols-5" :label="__('Database Type')">
                        @foreach(DatabaseType::cases() as $dbType)
                            <x-radio-card
                                :active="$form->database_type === $dbType->value"
                                :icon="$dbType->icon()"
                                :label="$dbType->label()"
                                :value="$dbType->value"
                                wire:model.live="form.database_type"
                            />
                        @endforeach
                    </x-radio-card-group>
                </div>

                @if($form->database_type)
                    @include('livewire.database-server._ssh-tunnel-config', ['form' => $form, 'isEdit' => $isEdit])

                    @if($form->isSqlite())
                        {{-- SQLite file paths now live on each backup configuration below.
                             SQLite needs no host/port/credentials here; connection testing
                             reads the paths from the first backup card. --}}
                    @else
                        <!-- Client-server database connection fields -->
                        <div class="grid gap-4 md:grid-cols-2">
                            <x-input
                                wire:model="form.host"
                                label="{{ __('Host') }}"
                                placeholder="{{ __('e.g., localhost or 192.168.1.100') }}"
                                type="text"
                                required
                            />

                            <x-input
                                wire:model="form.port"
                                label="{{ __('Port') }}"
                                placeholder="{{ __('e.g., 3306') }}"
                                type="number"
                                min="1"
                                max="65535"
                                required
                            />
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <x-input
                                wire:model="form.username"
                                label="{{ __('Username') }}"
                                placeholder="{{ $form->hasOptionalCredentials() ? __('Optional (for authenticated servers)') : __('Database username') }}"
                                type="text"
                                :required="!$form->hasOptionalCredentials()"
                                autocomplete="off"
                            />

                            <x-password
                                wire:model="form.password"
                                label="{{ __('Password') }}"
                                placeholder="{{ $isEdit ? __('Leave blank to keep current') : __('Database password') }}"
                                :required="!$isEdit && !$form->hasOptionalCredentials()"
                                autocomplete="off"
                            />
                        </div>

                        @if($form->isMongodb())
                            <x-input
                                wire:model="form.auth_source"
                                label="{{ __('Authentication Database') }}"
                                placeholder="admin"
                                hint="{{ __('The database used to authenticate credentials') }}"
                                type="text"
                            />
                        @endif
                    @endif

                    @if($form->supportsDumpFlags())
                        <x-collapse class="mt-2" :open="!empty($form->dump_flags)">
                            <x-slot:heading>
                                <x-icon name="o-command-line" class="w-4 h-4" />
                                {{ __('Dump Command Configuration') }}
                            </x-slot:heading>
                            <x-slot:content class="space-y-3">
                                <x-input
                                    wire:model.live.debounce.300ms="form.dump_flags"
                                    placeholder="{{ __('e.g., --no-tablespaces --verbose') }}"
                                    :hint="__('Additional flags appended to the dump command')"
                                    :label="__('Extra Dump Flags')"
                                    type="text"
                                />

                                @php $dumpPreview = $form->getDumpCommandPreview() @endphp
                                @if($dumpPreview)
                                    <x-badge :value="__('Command preview')" class="badge-primary badge-soft "/>
                                    <div class="mockup-code text-xs">
                                        <pre data-prefix="$"><code>{{ $dumpPreview }}</code></pre>
                                    </div>
                                @endif
                            </x-slot:content>
                        </x-collapse>
                    @endif

                    <!-- Test Connection Button -->
                    @if($form->hasAgent())
                        <x-alert class="alert-info mt-2" icon="o-information-circle">
                            {{ __('Connection testing is not available for agent-managed servers. The agent will test connectivity when running backups.') }}
                        </x-alert>
                    @else
                        <div class="flex flex-wrap items-center gap-2 pt-2">
                            <x-button
                                class="{{ $form->connectionTestSuccess ? 'btn-success' : 'btn-outline btn-primary' }}"
                                type="button"
                                icon="{{ $form->connectionTestSuccess ? 'o-check-circle' : 'o-bolt' }}"
                                wire:click="testConnection"
                                :disabled="$form->testingConnection"
                                spinner="testConnection"
                            >
                                @if($form->testingConnection)
                                    {{ __('Testing...') }}
                                @elseif($form->connectionTestSuccess)
                                    {{ __('Connection Verified') }}
                                    @if(!empty($form->connectionTestDetails['ping_ms']))
                                        ({{ $form->connectionTestDetails['ping_ms'] }}ms)
                                    @endif
                                @else
                                    {{ __('Test Connection') }}
                                @endif
                            </x-button>

                            @if($form->connectionTestSuccess && !empty($form->connectionTestDetails['output']))
                                <x-button
                                    wire:click="$toggle('form.showConnectionDetails')"
                                    class="btn-ghost btn-sm"
                                    icon="{{ $form->showConnectionDetails ? 'o-eye-slash' : 'o-eye' }}"
                                    :label="$form->showConnectionDetails ? __('Hide Details') : __('Show Details')"
                                />
                            @endif
                        </div>

                        <!-- Connection Test Result -->
                        @if($form->connectionTestMessage && !$form->connectionTestSuccess)
                            <x-alert class="alert-error mt-2" icon="o-x-circle">
                                <div>
                                    <span class="font-bold">{{ __('Connection failed') }}</span>
                                    <p class="text-sm">{{ $form->connectionTestMessage }}</p>
                                </div>
                                <x-button
                                    :label="__('Troubleshooting Guide')"
                                    link="https://david-crty.github.io/databasement/user-guide/database-servers/#troubleshooting-connection-issues"
                                    external
                                    class="btn-ghost btn-sm mt-2"
                                    icon="o-arrow-top-right-on-square"
                                />
                            </x-alert>
                        @endif

                        @if($form->connectionTestSuccess && !empty($form->connectionTestDetails['ssh_tunnel']))
                            <x-alert class="alert-info mt-2" icon="o-server-stack">
                                {{ __('Connected via SSH tunnel through') }} {{ $form->connectionTestDetails['ssh_host'] }}
                            </x-alert>
                        @elseif($form->connectionTestSuccess && !empty($form->connectionTestDetails['sftp']))
                            <x-alert class="alert-info mt-2" icon="o-server-stack">
                                {{ __('Connected via SFTP through') }} {{ $form->connectionTestDetails['ssh_host'] }}
                            </x-alert>
                        @endif

                        @if($form->showConnectionDetails && !empty($form->connectionTestDetails['output']))
                            <div class="mockup-code text-sm max-h-64 overflow-auto mt-2 max-w-full w-full overflow-x-auto">
                                @foreach(explode("\n", trim($form->connectionTestDetails['output'])) as $line)
                                    <pre class="!whitespace-pre-wrap !break-all"><code>{{ $line }}</code></pre>
                                @endforeach
                            </div>
                        @endif
                    @endif
                @endif
            </div>
        </div>
    </div>

    <!-- Enable Backups Toggle (shown after successful connection test, agent assigned, or when editing) -->
    @if($form->connectionTestSuccess or $form->hasAgent() or $isEdit)
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-3 sm:p-8">
                <x-toggle
                    wire:model.live="form.backups_enabled"
                    label="{{ __('Enable Scheduled Backups') }}"
                    hint="{{ __('When disabled, this server will be skipped during scheduled backup runs') }}"
                    class="toggle-primary"
                />
            </div>
        </div>
    @endif

    <!-- Section 3: Backup Configurations (collection of one or more) -->
    @if(($form->connectionTestSuccess or $form->hasAgent() or $isEdit or $form->isSqlite()) && $form->backups_enabled)
        @php
            $volumes = $form->getAllVolumes();
            $schedules = $form->getBackupSchedules();
            $volumeOptions = $form->getVolumeOptions();
            $scheduleOptions = $form->getScheduleOptions();
        @endphp

        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-3 sm:p-8">
                <!-- Card header -->
                <div class="flex items-start gap-3 mb-6">
                    <span class="badge badge-primary badge-lg font-bold">3</span>
                    <div>
                        <h3 class="card-title text-lg leading-snug">{{ __('Backup Configurations') }}</h3>
                        <p class="text-xs text-base-content/60 mt-0.5">
                            {{ __('Attach one or more backup configurations — each with its own schedule, volume, retention, and database selection.') }}
                        </p>
                    </div>
                </div>

                <div class="space-y-4">
                    @foreach($form->backups as $index => $backup)
                        @include('livewire.database-server._backup-form', [
                            'form' => $form,
                            'index' => $index,
                            'position' => $loop->iteration,
                            'backup' => $backup,
                            'volumes' => $volumes,
                            'schedules' => $schedules,
                            'volumeOptions' => $volumeOptions,
                            'scheduleOptions' => $scheduleOptions,
                        ])
                    @endforeach

                    <div class="flex justify-center pt-2">
                        <x-button
                            wire:click="addBackup"
                            icon="o-plus"
                            class="btn-outline btn-primary"
                            :label="__('Add another backup configuration')"
                            type="button"
                        />
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Section 4: Notifications -->
    @if($form->connectionTestSuccess or $form->hasAgent() or $isEdit or $form->isSqlite())
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-3 sm:p-8">
                <div class="flex items-center gap-3 mb-4">
                    <span class="badge badge-primary badge-lg font-bold">{{ $form->backups_enabled ? 4 : 3 }}</span>
                    <h3 class="card-title text-lg">{{ __('Notifications') }}</h3>
                </div>

                @php
                    $notificationChannels = $form->getNotificationChannels();
                    $hasChannels = $notificationChannels->isNotEmpty();
                    $isDisabled = $form->notification_trigger === 'none';
                    $triggerOptions = [
                        'all' => ['icon' => 'o-bell-alert', 'label' => __('All events'), 'hint' => __('Success & failure'), 'color' => 'info'],
                        'success' => ['icon' => 'o-check-circle', 'label' => __('Success only'), 'hint' => __('Completed backups'), 'color' => 'success'],
                        'failure' => ['icon' => 'o-exclamation-triangle', 'label' => __('Failure only'), 'hint' => __('Errors & timeouts'), 'color' => 'error'],
                        'none' => ['icon' => 'o-bell-slash', 'label' => __('Disabled'), 'hint' => __('No notifications'), 'color' => 'default'],
                    ];
                @endphp

                <div class="space-y-5">
                    <!-- Trigger selection -->
                    <div class="space-y-2">
                        <div>
                            <p class="text-sm font-semibold">{{ __('Notify me on') }}</p>
                            <p class="text-xs text-base-content/60">{{ __('When should this server send a notification?') }}</p>
                        </div>
                        <x-radio-card-group class="grid-cols-2 sm:grid-cols-4" :label="__('Notification trigger')">
                            @foreach($triggerOptions as $value => $opt)
                                <x-radio-card
                                    :active="$form->notification_trigger === $value"
                                    :color="$opt['color']"
                                    :icon="$opt['icon']"
                                    :label="$opt['label']"
                                    :hint="$opt['hint']"
                                    :value="$value"
                                    wire:model.live="form.notification_trigger"
                                />
                            @endforeach
                        </x-radio-card-group>
                    </div>

                    <!-- Channel selection (hidden when disabled) -->
                    @if(! $isDisabled)
                        @if(! $hasChannels)
                            <!-- Empty state: no channels exist at all -->
                            <div class="flex flex-col items-center justify-center gap-4 rounded-lg border-2 border-dashed border-base-300 bg-base-200/50 px-6 py-10 text-center">
                                <span class="inline-flex items-center justify-center rounded-full bg-base-200 p-3">
                                    <x-icon name="o-bell-alert" class="w-6 h-6 text-base-content/50" />
                                </span>
                                <div class="space-y-1">
                                    <p class="text-sm font-semibold">{{ __('No notification channels yet') }}</p>
                                    <p class="text-xs text-base-content/60 max-w-xs">
                                        {{ __('Add at least one channel (Email, Slack, Webhook…) to receive backup alerts.') }}
                                    </p>
                                </div>
                                <x-button
                                    icon="o-plus"
                                    class="btn-primary btn-sm"
                                    link="{{ route('configuration.index') }}#notification-channels"
                                    external
                                    :label="__('Create your first channel')"
                                />
                            </div>
                        @else
                            <!-- Channel selection mode -->
                            <div class="space-y-2">
                                <div>
                                    <p class="text-sm font-semibold">{{ __('Send to') }}</p>
                                    <p class="text-xs text-base-content/60">{{ __('Target one or all of your notification channels.') }}</p>
                                </div>
                                @php
                                    $modeOptions = [
                                        'all' => [
                                            'icon' => 'o-user-group',
                                            'label' => __('All channels'),
                                            'hint' => __(':count configured', ['count' => $notificationChannels->count()]),
                                        ],
                                        'selected' => [
                                            'icon' => 'o-adjustments-horizontal',
                                            'label' => __('Specific channels'),
                                            'hint' => __('Pick individual channels'),
                                        ],
                                    ];
                                @endphp
                                <x-radio-card-group class="grid-cols-1 sm:grid-cols-2" :label="__('Send to')">
                                    @foreach($modeOptions as $value => $opt)
                                        <x-radio-card
                                            :active="$form->notification_channel_selection === $value"
                                            :icon="$opt['icon']"
                                            :label="$opt['label']"
                                            :hint="$opt['hint']"
                                            :value="$value"
                                            horizontal
                                            wire:model.live="form.notification_channel_selection"
                                        />
                                    @endforeach
                                </x-radio-card-group>
                            </div>

                            <!-- Channel picker (when 'selected') -->
                            @if($form->notification_channel_selection === 'selected')
                                @php $hasChannelError = $errors->has('form.notification_channel_ids'); @endphp
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between gap-2">
                                        <div>
                                            <p class="text-sm font-semibold {{ $hasChannelError ? 'text-error' : '' }}">{{ __('Select channels') }}</p>
                                            <p class="text-xs {{ $hasChannelError ? 'text-error/80' : 'text-base-content/60' }}">
                                                {{ __(':selected of :total selected', ['selected' => count($form->notification_channel_ids), 'total' => $notificationChannels->count()]) }}
                                            </p>
                                        </div>
                                        @if(count($form->notification_channel_ids) > 0)
                                            <button
                                                type="button"
                                                wire:click="$set('form.notification_channel_ids', [])"
                                                class="text-xs text-base-content/60 hover:text-base-content hover:underline cursor-pointer"
                                            >
                                                {{ __('Clear all') }}
                                            </button>
                                        @endif
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                        @foreach($notificationChannels as $channel)
                                            @php $isSelected = in_array($channel->id, $form->notification_channel_ids, true); @endphp
                                            <button
                                                type="button"
                                                role="checkbox"
                                                aria-checked="{{ $isSelected ? 'true' : 'false' }}"
                                                wire:click="toggleNotificationChannel('{{ $channel->id }}')"
                                                wire:key="channel-card-{{ $channel->id }}"
                                                class="relative flex items-center gap-3 rounded-lg border-2 p-3 text-left transition-all cursor-pointer {{ $isSelected ? 'border-primary bg-primary/5 shadow-sm' : 'border-base-300 bg-base-100 hover:bg-base-200' }}"
                                            >
                                                <span class="shrink-0 rounded-md p-2 {{ $isSelected ? 'bg-primary/10 text-primary' : 'bg-base-200 text-base-content/60' }}">
                                                    <x-icon :name="$channel->type->icon()" class="w-5 h-5" />
                                                </span>
                                                <span class="flex-1 min-w-0">
                                                    <span class="block text-sm font-semibold truncate">{{ $channel->name }}</span>
                                                    <span class="block text-xs text-base-content/60 truncate">{{ $channel->type->label() }}</span>
                                                </span>
                                                <span class="shrink-0 w-5 h-5 rounded-md border-2 flex items-center justify-center {{ $isSelected ? 'border-primary bg-primary' : 'border-base-300' }}">
                                                    @if($isSelected)
                                                        <x-icon name="s-check" class="w-3.5 h-3.5 text-primary-content" />
                                                    @endif
                                                </span>
                                            </button>
                                        @endforeach
                                    </div>
                                    @if($hasChannelError)
                                        <x-alert class="alert-error" icon="o-x-circle">
                                            {{ __('Select at least one channel, or switch to “All channels”.') }}
                                        </x-alert>
                                    @endif
                                </div>
                            @endif
                        @endif
                    @endif

                    <!-- Live summary -->
                    @php
                        $channelCount = $form->notification_channel_selection === 'all'
                            ? $notificationChannels->count()
                            : count($form->notification_channel_ids);
                        $summaryHasChannels = $channelCount > 0;
                        $triggerLabels = [
                            'all' => __('all events'),
                            'success' => __('success events only'),
                            'failure' => __('failure events only'),
                        ];
                    @endphp
                    @if($isDisabled)
                        <div class="flex items-start gap-2.5 rounded-lg border border-base-300 bg-base-200 px-4 py-3">
                            <x-icon name="o-bell-slash" class="w-5 h-5 text-base-content/50 shrink-0 mt-0.5" />
                            <p class="text-sm text-base-content/70 leading-snug">
                                {{ __('Notifications are disabled for this server. No alerts will be sent.') }}
                            </p>
                        </div>
                    @elseif($hasChannels && $summaryHasChannels)
                        <div class="flex items-start gap-2.5 rounded-lg border border-success/30 bg-success/5 px-4 py-3">
                            <x-icon name="o-bell-alert" class="w-5 h-5 text-success shrink-0 mt-0.5" />
                            <p class="text-sm leading-snug">
                                {{ __('Notifications will be sent to') }}
                                <span class="font-semibold">{{ trans_choice('{1} :count channel|[2,*] :count channels', $channelCount, ['count' => $channelCount]) }}</span>
                                {{ __('on') }}
                                <span class="font-semibold">{{ $triggerLabels[$form->notification_trigger] ?? '' }}</span>.
                            </p>
                        </div>
                    @elseif($hasChannels)
                        <div class="flex items-start gap-2.5 rounded-lg border border-warning/30 bg-warning/5 px-4 py-3">
                            <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-warning shrink-0 mt-0.5" />
                            <p class="text-sm leading-snug">
                                <span class="font-semibold">{{ __('No channels selected.') }}</span>
                                {{ __('Pick at least one channel above, or switch to “All channels”.') }}
                            </p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <!-- Form Actions -->
    <div class="flex flex-col-reverse sm:flex-row items-center justify-end gap-3 pt-4">
        <x-button class="btn-ghost w-full sm:w-auto" link="{{ route($cancelRoute) }}" wire:navigate>
            {{ __('Cancel') }}
        </x-button>
        <x-button
            class="btn-primary w-full sm:w-auto"
            type="submit"
            icon="o-check"
            spinner="save"
        >
            {{ __($submitLabel) }}
        </x-button>
    </div>
</x-form>
