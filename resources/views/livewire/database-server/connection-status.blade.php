<div>
    <x-popover>
        <x-slot:trigger>
            @if($loading)
                <span role="button" tabindex="0" aria-label="{{ __('Checking connection') }}" class="block size-2.5 rounded-full bg-base-content/20 ring-2 ring-base-100 animate-ping cursor-pointer"></span>
            @else
                <span role="button" tabindex="0" aria-label="{{ $success ? __('Connection successful') : __('Connection failed') }}" class="block size-2.5 rounded-full ring-2 ring-base-100 cursor-pointer {{ $success ? 'bg-success' : 'bg-error' }}"></span>
            @endif
        </x-slot:trigger>
        <x-slot:content class="text-sm">
            {{ $message }}
        </x-slot:content>
    </x-popover>
</div>
