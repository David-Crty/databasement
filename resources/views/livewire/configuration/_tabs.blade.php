<div role="tablist" class="tabs tabs-border mb-6">
    <a href="{{ route('configuration.application') }}" wire:navigate
       role="tab" @class(['tab', 'tab-active' => $active === 'application'])>
        {{ __('Application') }}
    </a>
    <a href="{{ route('configuration.backup') }}" wire:navigate
       role="tab" @class(['tab', 'tab-active' => $active === 'backup'])>
        {{ __('Backup') }}
    </a>
    <a href="{{ route('configuration.notification') }}" wire:navigate
       role="tab" @class(['tab', 'tab-active' => $active === 'notification'])>
        {{ __('Notification') }}
    </a>
    <a href="{{ route('configuration.authentication') }}" wire:navigate
       role="tab" @class(['tab', 'tab-active' => $active === 'authentication'])>
        {{ __('Authentication') }}
    </a>
</div>
