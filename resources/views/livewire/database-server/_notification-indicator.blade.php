@php
    use App\Enums\NotificationTrigger;
    use App\Enums\NotificationChannelSelection;

    $trigger = $server->notification_trigger;
    $selection = $server->notification_channel_selection;

    $iconName = $trigger === NotificationTrigger::None ? 'o-bell-slash' : 'o-bell';
    $iconClass = match ($trigger) {
        NotificationTrigger::None => 'text-base-content/30',
        NotificationTrigger::Failure => 'text-warning',
        NotificationTrigger::Success => 'text-info',
        NotificationTrigger::All => 'text-success',
    };

    $selectedChannels = $server->notificationChannels;
    $channelCount = $selection === NotificationChannelSelection::Selected
        ? $selectedChannels->count()
        : ($totalNotificationChannels ?? 0);
@endphp

<x-popover>
    <x-slot:trigger>
        <x-icon :name="$iconName" class="w-4 h-4 {{ $iconClass }} cursor-pointer" />
    </x-slot:trigger>
    <x-slot:content class="text-sm space-y-1">
        <div class="font-semibold">{{ $trigger->label() }}</div>
        @if($trigger !== NotificationTrigger::None)
            <div class="text-base-content/70">
                @if($selection === NotificationChannelSelection::All)
                    {{ __('All channels') }} ({{ $channelCount }})
                @else
                    {{ __('Selected channels') }} ({{ $channelCount }})
                @endif
            </div>
            @if($channelCount === 0)
                <div class="text-warning text-xs">{{ __('No channels configured.') }}</div>
            @elseif($selection === NotificationChannelSelection::Selected)
                <ul class="text-xs text-base-content/60 list-disc list-inside">
                    @foreach($selectedChannels->take(5) as $channel)
                        <li>{{ $channel->name }} ({{ $channel->type->label() }})</li>
                    @endforeach
                    @if($selectedChannels->count() > 5)
                        <li class="list-none">{{ __('... and :count more', ['count' => $selectedChannels->count() - 5]) }}</li>
                    @endif
                </ul>
            @endif
        @endif
    </x-slot:content>
</x-popover>
