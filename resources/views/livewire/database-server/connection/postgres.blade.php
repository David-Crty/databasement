@props(['form', 'isEdit' => false])

@include('livewire.database-server.connection._client-server-fields', ['form' => $form, 'isEdit' => $isEdit])

<x-checkbox
    wire:model.live="form.ssl_enabled"
    :label="__('Use SSL')"
    :hint="__('Forces TLS (sslmode=require) for servers that enforce encrypted connections, such as Neon or Amazon RDS. The server certificate is not verified.')"
/>
