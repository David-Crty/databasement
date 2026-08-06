@props(['form', 'submitLabel' => 'Save', 'cancelRoute' => 'volumes.index', 'readonly' => false])

@php
use App\Enums\VolumeType;
@endphp

<form wire:submit="save" class="space-y-6">
    <!-- Basic Information -->
    <div class="space-y-4">
        <h3 class="text-lg font-semibold">{{ __('Basic Information') }}</h3>

        <x-input
            wire:model="form.name"
            label="{{ __('Volume Name') }}"
            placeholder="{{ __('e.g., Production S3 Bucket') }}"
            type="text"
            required
        />

        <x-input
            wire:model.live.debounce="form.maxStorageGb"
            :label="__('Maximum storage (GB)')"
            :hint="__('Optional. A backup that would push this volume’s total size over the limit is rejected before uploading — no snapshots are deleted automatically. Free up space by removing old snapshots, or enable notify-only to keep backing up. Leave empty for no limit.')"
            :placeholder="__('e.g., 10')"
            type="number"
            step="0.1"
            min="0"
            suffix="GB"
        />

        @if (filled($form->maxStorageGb))
            <x-checkbox
                wire:model="form.storageLimitNotifyOnly"
                :label="__('When the storage limit is reached, only notify — don’t block backups')"
                :hint="__('Upload the backup anyway and send a notification instead of failing it.')"
            />
        @endif

        <!-- Storage Type Selection (immutable after creation) -->
        @php $typeDisabled = $readonly || $form->volume !== null; @endphp
        <div>
            <label class="label label-text font-semibold mb-2">{{ __('Storage Type') }}</label>
            <x-radio-card-group class="grid-cols-2 sm:grid-cols-3 lg:grid-cols-6" :label="__('Storage Type')">
                @foreach(VolumeType::cases() as $volumeType)
                    <x-radio-card
                        :active="$form->type === $volumeType->value"
                        :icon="$volumeType->icon()"
                        :label="$volumeType->label()"
                        :value="$volumeType->value"
                        :disabled="$typeDisabled"
                        wire:model.live="form.type"
                    />
                @endforeach
            </x-radio-card-group>
        </div>

        <!-- Remote volume: reachable only from an agent -->
        @php $agentOptions = $form->getAgentOptions(); @endphp
        @if(count($agentOptions) > 0 || $form->hasAgent())
            <div class="border border-base-300 rounded-lg bg-base-200">
                <label class="flex items-start gap-3 p-4 cursor-pointer select-none">
                    <x-toggle
                        wire:model.live="form.use_agent"
                        class="toggle-primary"
                        :disabled="$readonly"
                    />
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-medium">{{ __('Volume is only reachable from an agent') }}</span>
                            <span class="badge badge-ghost badge-sm text-base-content/50 font-normal">{{ __('Optional') }}</span>
                        </div>
                        <p class="text-xs text-base-content/50 mt-0.5 leading-relaxed">
                            {{ __('Enable this when the storage lives inside your private network. Connection tests, uploads and deletions all run on the agent instead of this app.') }}
                        </p>
                    </div>
                </label>

                @if($form->use_agent)
                    <div class="border-t border-base-300 bg-base-100 p-4 rounded-b-lg space-y-3">
                        <x-select
                            wire:model.live="form.agent_id"
                            :label="__('Agent')"
                            :options="$agentOptions"
                            :placeholder="__('Select an agent')"
                            placeholder-value=""
                            :disabled="$readonly"
                        />

                        @php $selectedAgent = $form->getSelectedAgent(); @endphp
                        @if($selectedAgent)
                            <div class="flex items-center gap-2 text-sm">
                                <x-agent-status-indicator :status="$selectedAgent->connectionStatus()" />
                                @if($selectedAgent->last_heartbeat_at)
                                    <span class="text-base-content/70">{{ __('Last heartbeat :time', ['time' => $selectedAgent->last_heartbeat_at->diffForHumans()]) }}</span>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </div>

    <!-- Configuration -->
    <x-hr />

    <div class="space-y-4">
        <h3 class="text-lg font-semibold">{{ __('Configuration') }}</h3>

        @php
            $configPrefix = 'form.' . VolumeType::from($form->type)->configPropertyName();
            $isEditing = $form->volume !== null;
        @endphp

        @include('livewire.volume.connectors.' . $form->type . '-config', [
            'configPrefix' => $configPrefix,
            'readonly' => $readonly,
            'isEditing' => $isEditing,
        ])

        <!-- Test Connection Button -->
        <div class="pt-2">
            {{-- A remote test returns immediately and is answered later by the
                 agent, so the button keeps spinning off-request to match the
                 local test's feedback. --}}
            <x-button
                class="w-full btn-outline {{ $form->testingConnection ? 'loading' : '' }}"
                type="button"
                icon="o-arrow-path"
                wire:click="testConnection"
                :disabled="$form->testingConnection"
                spinner="testConnection"
            >
                @if($form->testingConnection)
                    {{ __('Testing Connection...') }}
                @elseif($form->use_agent)
                    {{ __('Test Connection on the agent') }}
                @else
                    {{ __('Test Connection') }}
                @endif
            </x-button>
        </div>

        {{-- The agent only sees the job on its next poll, so wait for its answer. --}}
        @if($form->connectionTestJobId !== null)
            <div wire:poll.2s="pollConnectionTest"></div>
        @endif

        <!-- Connection Test Result -->
        @if($form->connectionTestMessage)
            <div class="mt-2">
                @if($form->testingConnection)
                    {{-- Still waiting on the agent: not an outcome yet. --}}
                    <x-alert class="alert-warning" icon="o-clock">
                        {{ $form->connectionTestMessage }}
                    </x-alert>
                @elseif($form->connectionTestSuccess)
                    <x-alert class="alert-success" icon="o-check-circle">
                        {{ $form->connectionTestMessage }}
                    </x-alert>
                @else
                    <x-alert class="alert-error" icon="o-x-circle">
                        {{ $form->connectionTestMessage }}
                    </x-alert>
                @endif
            </div>
        @endif
    </div>

    <!-- Submit Button -->
    <div class="flex items-center justify-end gap-3 pt-4">
        <x-button class="btn-ghost" link="{{ route($cancelRoute) }}" wire:navigate>
            {{ __('Cancel') }}
        </x-button>
        <x-button class="btn-primary" type="submit">
            {{ __($submitLabel) }}
        </x-button>
    </div>
</form>
