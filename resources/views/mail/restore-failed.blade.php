<x-mail::message>
# Restore Failed

A restore job has failed and requires your attention.

<x-mail::panel>
**Target Server:** {{ $targetServerName }}<br>
**Target Database:** {{ $schemaName }}<br>
**Source Snapshot:** {{ $snapshotFilename }}<br>
**Time:** {{ $timestamp }}
</x-mail::panel>

## Error Details

<x-mail::panel>
{{ $errorMessage }}
</x-mail::panel>

<x-mail::button :url="$jobUrl" color="primary">
View Job Details
</x-mail::button>

---

This is an automated notification from {{ config('app.name') }}. Please investigate the issue and take appropriate action.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
