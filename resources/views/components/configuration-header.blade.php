@props(['active', 'subtitle'])

<x-header :title="__('Configuration')" :subtitle="$subtitle" separator />

@include('livewire.configuration._tabs', ['active' => $active])
