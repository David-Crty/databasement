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
            <x-button
                class="w-full btn-outline"
                type="button"
                icon="o-arrow-path"
                wire:click="testConnection"
                :disabled="$form->testingConnection"
                spinner="testConnection"
            >
                @if($form->testingConnection)
                    {{ __('Testing Connection...') }}
                @else
                    {{ __('Test Connection') }}
                @endif
            </x-button>
        </div>

        <!-- Connection Test Result -->
        @if($form->connectionTestMessage)
            <div class="mt-2">
                @if($form->connectionTestSuccess)
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
