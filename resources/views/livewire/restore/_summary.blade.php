{{--
    Restore summary card used by both the one-shot Restore modal and the
    Scheduled Restore modal.

    Params (all strings, $size and $schedule optional):
      $source   - source server + database label
      $snapshot - snapshot label (a date for one-shot, "Latest at run time" for scheduled)
      $target   - target server + schema label
      $size     - optional human-readable file size; line is hidden when null
      $schedule - optional schedule label (scheduled restores only)
--}}
<div class="p-4 border rounded-lg bg-base-200 border-base-300">
    <div class="text-sm font-semibold mb-2">{{ __('Restore Summary') }}</div>
    <div class="text-sm opacity-70 space-y-1">
        <div><strong>{{ __('Source:') }}</strong> {{ $source }}</div>
        <div><strong>{{ __('Snapshot:') }}</strong> {{ $snapshot }}</div>
        <div><strong>{{ __('Target:') }}</strong> {{ $target }}</div>
        @if(! empty($size))
            <div><strong>{{ __('Size:') }}</strong> {{ $size }}</div>
        @endif
        @if(! empty($schedule))
            <div><strong>{{ __('Schedule:') }}</strong> {{ $schedule }}</div>
        @endif
    </div>
</div>
