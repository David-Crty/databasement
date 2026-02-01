<x-mail::message>
# {{ $title }}

{{ $body }}

<x-mail::panel>
@foreach($fields as $label => $value)
**{{ $label }}:** {{ $value }}<br>
@endforeach
**Time:** {{ $footerText }}
</x-mail::panel>

## Error Details

<x-mail::panel>
{{ $errorMessage }}
</x-mail::panel>

<x-mail::button :url="$actionUrl" color="primary">
{{ $actionText }}
</x-mail::button>

---

This is an automated notification from {{ config('app.name') }}. Please investigate the issue and take appropriate action.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
