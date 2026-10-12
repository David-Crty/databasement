@props(['form', 'isEdit' => false])

@include('livewire.database-server.connection._client-server-fields', ['form' => $form, 'isEdit' => $isEdit])

<div class="grid gap-4 md:grid-cols-2 md:items-start">
    <x-checkbox
        class="md:mt-9"
        wire:model.live="form.ssl_enabled"
        :label="__('Use SSL')"
        :hint="__('Forces TLS (sslmode=require) for servers that enforce encrypted connections, such as Neon or Amazon RDS. The server certificate is not verified.')"
    />

    <x-input
        wire:model.blur="form.connection_database"
        :label="__('Connection database')"
        placeholder="postgres"
        :hint="__('Opened to test the connection and list databases.')"
    />
</div>
