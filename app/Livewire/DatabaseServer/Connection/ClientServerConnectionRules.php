<?php

namespace App\Livewire\DatabaseServer\Connection;

use App\Livewire\DatabaseServer\Form;

/**
 * Default rules for networked databases that authenticate with
 * host/port/username/password (MySQL, PostgreSQL, MSSQL, Firebird, …).
 */
class ClientServerConnectionRules extends ConnectionRules
{
    public function rules(Form $form): array
    {
        return [
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'required|string|max:255',
            'password' => 'nullable',
        ];
    }

    public function testConnectionRules(Form $form): array
    {
        // Same fields as the full validation, but a password is required when
        // creating (an existing server can fall back to its stored password).
        return array_merge($this->rules($form), [
            'password' => $form->server === null ? 'required|string|max:255' : 'nullable',
        ]);
    }
}
