@props(['form', 'isEdit' => false])

{{-- TimescaleDB connects exactly like PostgreSQL (host/port/credentials, SSL,
     connection database), so the Postgres connection fields are reused verbatim. --}}
@include('livewire.database-server.connection.postgres', ['form' => $form, 'isEdit' => $isEdit])
