<x-mail::message>
# {{ $title }}

{{ $body }}

<x-mail::panel>
@foreach($fields as $label => $value)
**{{ $label }}:** {{ $value }}<br>
@endforeach
**Time:** {{ $footerText }}
</x-mail::panel>

<x-mail::button :url="$actionUrl" color="success">
{{ $actionText }}
</x-mail::button>

---

This is an automated notification from {{ config('app.name') }}.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
