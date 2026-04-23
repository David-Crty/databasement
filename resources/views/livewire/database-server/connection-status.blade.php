<div>
    @if($loading)
        <span title="{{ __('Checking connection') }}" class="block size-2.5 rounded-full bg-base-content/20 ring-2 ring-base-100 animate-ping"></span>
    @else
        <span aria-label="{{ $message }}" title="{{ $message }}" class="block size-2.5 rounded-full ring-2 ring-base-100 {{ $success ? 'bg-success' : 'bg-error' }}"></span>
    @endif
</div>
