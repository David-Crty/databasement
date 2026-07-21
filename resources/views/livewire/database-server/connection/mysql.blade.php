@props(['form', 'isEdit' => false])

@include('livewire.database-server.connection._client-server-fields', ['form' => $form, 'isEdit' => $isEdit])

<x-checkbox
    wire:model.live="form.ssl_enabled"
    :label="__('Use SSL')"
    :hint="__('Required for servers that enforce TLS, such as Amazon RDS with require_secure_transport. The server certificate is not verified.')"
/>
