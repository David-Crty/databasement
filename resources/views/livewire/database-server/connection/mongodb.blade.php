@props(['form', 'isEdit' => false])

{{-- SRV connections resolve host/port from DNS, so the port field is hidden. --}}
@include('livewire.database-server.connection._client-server-fields', [
    'form' => $form,
    'isEdit' => $isEdit,
    'showPort' => ! $form->srv_enabled,
])

<x-input
    wire:model.live.debounce.300ms="form.auth_source"
    :label="__('Authentication Database')"
    placeholder="admin"
    :hint="__('The database used to authenticate credentials')"
    type="text"
/>

<x-checkbox
    wire:model.live="form.srv_enabled"
    :label="__('Use DNS Seed List (SRV)')"
    :hint="__('For MongoDB Atlas and clusters using mongodb+srv connection strings. The port is resolved from DNS.')"
/>

<div>
    <x-input
        wire:model.live.debounce.300ms="form.connection_options"
        :label="__('Connection Options')"
        placeholder="tls=true&replicaSet=rs0&retryWrites=true"
        :hint="__('Optional. key=value parameters for the connection string — set TLS, replica set and anything else here (e.g. tls=true, replicaSet=rs0).')"
        type="text"
    />
    <a href="https://www.mongodb.com/docs/manual/reference/connection-string-options/"
       target="_blank" rel="noopener"
       class="link link-primary text-xs">{{ __('MongoDB connection string options reference') }}</a>
</div>
