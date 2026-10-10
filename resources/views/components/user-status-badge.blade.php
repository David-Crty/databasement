@props(['user'])

@if($user->isActive())
    <x-badge :value="__('Active')" class="badge-success" />
@else
    <x-badge :value="__('Pending')" class="badge-warning" />
@endif
