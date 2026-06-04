<div>
    <x-modal wire:model="showModal" box-class="max-w-2xl w-11/12" class="backdrop-blur">
        <x-header
            :title="$editingId ? __('Edit Scheduled Restore') : __('New Scheduled Restore')"
            icon="bi.clock-history"
            icon-classes="text-success w-6 h-6"
            size="text-xl"
            class="!mb-5"
        />

        <div class="space-y-5">
            <ul class="steps steps-horizontal w-full">
                <li class="step {{ $currentStep >= 1 ? 'step-primary' : '' }}">{{ __('Source') }}</li>
                <li class="step {{ $currentStep >= 2 ? 'step-primary' : '' }}">{{ __('Target') }}</li>
                <li class="step {{ $currentStep >= 3 ? 'step-primary' : '' }}">{{ __('Schedule') }}</li>
            </ul>

            @if($currentStep === 1)
                <div class="space-y-4">
                    <p class="text-sm opacity-70">
                        {!! __('Choose the source server. On each run, the :snapshot from this server is selected automatically.', [
                            'snapshot' => '<strong>'.e(__('latest completed snapshot')).'</strong>',
                        ]) !!}
                    </p>

                    <x-select
                        :label="__('Source server')"
                        wire:model.live="sourceServerId"
                        :options="$this->sourceServerOptions"
                        :placeholder="__('Select a source server')"
                        placeholder-value=""
                    />

                    @if($this->sourceServerRequiresDatabase)
                        <x-select
                            :label="__('Source database')"
                            wire:model="sourceDatabaseName"
                            :options="$this->sourceDatabaseOptions"
                            :placeholder="__('Select a database')"
                            placeholder-value=""
                            :disabled="empty($this->sourceDatabaseOptions)"
                            :hint="empty($this->sourceDatabaseOptions) ? __('No completed snapshots are available for this server yet.') : null"
                        />
                    @endif
                </div>
            @endif

            @if($currentStep === 2)
                <div class="space-y-4">
                    <p class="text-sm opacity-70">
                        {{ __('Choose where the snapshot will be restored on each run.') }}
                    </p>

                    @include('livewire.restore._destination-step', [
                        'targetLocked' => false,
                        'snapshot' => null,
                    ])
                </div>
            @endif

            @if($currentStep === 3)
                <div class="space-y-4">
                    <p class="text-sm opacity-70">
                        {{ __('Name this scheduled restore and pick how often it should run.') }}
                    </p>

                    <x-input
                        :label="__('Name')"
                        wire:model="name"
                        :placeholder="__('Refresh staging nightly')"
                    />

                    <x-select
                        :label="__('Schedule')"
                        wire:model="backupScheduleId"
                        :options="$this->backupScheduleOptions"
                        :placeholder="__('Select a schedule')"
                        placeholder-value=""
                        :hint="__('Manage schedules in Configuration → Backup.')"
                    />

                    <x-checkbox
                        :label="__('Enabled')"
                        wire:model="enabled"
                    />
                </div>
            @endif
        </div>

        <x-slot:actions>
            <div class="flex items-center gap-2 w-full justify-between">
                <div>
                    @if($currentStep > 1)
                        <x-button :label="__('Back')" icon="o-arrow-left" wire:click="previousStep" class="btn-ghost" />
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    <x-button :label="__('Cancel')" @click="$wire.showModal = false" class="btn-ghost" />
                    @if($currentStep < 3)
                        <x-button :label="__('Next')" icon-right="o-arrow-right" wire:click="nextStep" class="btn-primary" />
                    @else
                        <x-button
                            :label="$editingId ? __('Save changes') : __('Create scheduled restore')"
                            icon="o-check"
                            wire:click="save"
                            spinner="save"
                            class="btn-primary"
                        />
                    @endif
                </div>
            </div>
        </x-slot:actions>
    </x-modal>
</div>
