<x-mail::message>
# {{ $title }}

{{ $body }}

<x-mail::panel>
@foreach($fields as $label => $value)
**{{ $label }}:** {{ $value }}<br>
@endforeach
**Time:** {{ $footerText }}
</x-mail::panel>

@if($errorMessage !== null)
## Error Details

<x-mail::panel>
{{ $errorMessage }}
</x-mail::panel>
@endif

<x-mail::button :url="$actionUrl" :color="$buttonColor">
{{ $actionText }}
</x-mail::button>

---

This is an automated notification from {{ config('app.name') }}.@if($actionRequired) Please investigate the issue and take appropriate action.@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
