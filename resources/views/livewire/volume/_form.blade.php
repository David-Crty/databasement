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

        <!-- Storage Type Selection -->
        <div>
            <label class="label label-text font-semibold mb-2">{{ __('Storage Type') }}</label>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                @foreach(VolumeType::cases() as $volumeType)
                    @php
                        $isSelected = $form->type === $volumeType->value;
                        $buttonClass = match(true) {
                            $isSelected && $readonly => 'btn-primary opacity-70 cursor-not-allowed border-2 border-primary',
                            $isSelected => 'btn-primary',
                            $readonly => 'btn-outline opacity-40 cursor-not-allowed',
                            default => 'btn-outline',
                        };
                    @endphp
                    <button
                        type="button"
                        wire:click="$set('form.type', '{{ $volumeType->value }}')"
                        @if($readonly) disabled @endif
                        class="btn justify-start gap-2 h-auto py-3 {{ $buttonClass }}"
                    >
                        <x-volume-type-icon :type="$volumeType" class="w-5 h-5" />
                        <span>{{ $volumeType->label() }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Configuration -->
    <x-hr />

    <div class="space-y-4">
        <h3 class="text-lg font-semibold">{{ __('Configuration') }}</h3>

        @if($form->type === 'local')
            <livewire:volume.connectors.local-config
                wire:model="form.localConfig"
                :readonly="$readonly"
                wire:key="local-config"
            />
        @elseif($form->type === 's3')
            <livewire:volume.connectors.s3-config
                wire:model="form.s3Config"
                :readonly="$readonly"
                wire:key="s3-config"
            />
        @elseif($form->type === 'sftp')
            <livewire:volume.connectors.sftp-config
                wire:model="form.sftpConfig"
                :readonly="$readonly"
                :is-editing="$form->volume !== null"
                wire:key="sftp-config"
            />
        @elseif($form->type === 'ftp')
            <livewire:volume.connectors.ftp-config
                wire:model="form.ftpConfig"
                :readonly="$readonly"
                :is-editing="$form->volume !== null"
                wire:key="ftp-config"
            />
        @endif

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
