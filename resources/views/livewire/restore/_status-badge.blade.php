{{--
    Renders a restore job status as a Mary UI badge. Shared by the restore index
    "Status" column and the scheduled-restore "Last run" cell.

    Params:
      $status (string) - one of: completed | failed | running | pending
--}}
@if($status === 'completed')
    <x-badge :value="__('Completed')" class="badge-success badge-soft badge-sm h-auto py-1 whitespace-normal text-center" />
@elseif($status === 'failed')
    <x-badge :value="__('Failed')" class="badge-error badge-soft badge-sm h-auto py-1 whitespace-normal text-center" />
@elseif($status === 'running')
    <x-badge class="badge-warning badge-soft badge-sm gap-1 h-auto py-1 whitespace-normal text-center">
        <x-loading class="loading-spinner loading-xs shrink-0" />
        {{ __('Running') }}
    </x-badge>
@else
    <x-badge :value="__('Pending')" class="badge-info badge-soft badge-sm h-auto py-1 whitespace-normal text-center" />
@endif
