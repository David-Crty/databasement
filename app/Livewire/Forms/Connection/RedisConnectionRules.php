<?php

namespace App\Livewire\Forms\Connection;

use App\Livewire\Forms\DatabaseServerForm;

/**
 * Redis servers may run without AUTH, so credentials are optional and the
 * connection test only needs a reachable host/port.
 */
class RedisConnectionRules extends ClientServerConnectionRules
{
    public function rules(DatabaseServerForm $form): array
    {
        return array_merge(parent::rules($form), [
            'username' => 'nullable|string|max:255',
        ]);
    }

    public function testConnectionRules(DatabaseServerForm $form): array
    {
        return [
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
        ];
    }
}
