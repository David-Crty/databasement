@props(['form', 'isEdit' => false])

@include('livewire.database-server.connection._client-server-fields', ['form' => $form, 'isEdit' => $isEdit])
