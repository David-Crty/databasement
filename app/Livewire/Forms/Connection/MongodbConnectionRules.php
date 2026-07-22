<?php

namespace App\Livewire\Forms\Connection;

use App\Livewire\Forms\DatabaseServerForm;

class MongodbConnectionRules extends ClientServerConnectionRules
{
    public function rules(DatabaseServerForm $form): array
    {
        return array_merge(parent::rules($form), [
            'port' => $this->portRule($form),
            'username' => 'nullable|string|max:255',
            'auth_source' => 'nullable|string|max:255',
            'srv_enabled' => 'boolean',
            'connection_options' => 'nullable|string|max:500',
        ]);
    }

    public function testConnectionRules(DatabaseServerForm $form): array
    {
        return [
            'host' => 'required|string|max:255',
            'port' => $this->portRule($form),
        ];
    }

    /**
     * SRV connections resolve host/port from DNS, so no port is given.
     */
    private function portRule(DatabaseServerForm $form): string
    {
        return $form->srv_enabled
            ? 'nullable|integer|min:1|max:65535'
            : 'required|integer|min:1|max:65535';
    }

    public function extraConfig(DatabaseServerForm $form): array
    {
        return [
            'auth_source' => $form->auth_source,
            'srv_enabled' => $form->srv_enabled,
            'connection_options' => $form->connection_options,
        ];
    }

    public function dumpPreviewConfig(DatabaseServerForm $form): array
    {
        return [
            'auth_source' => $form->auth_source ?: 'admin',
            'srv' => $form->srv_enabled,
            'connection_options' => $form->connection_options,
        ];
    }

    public function applyDefaults(DatabaseServerForm $form): void
    {
        if ($form->auth_source === '') {
            $form->auth_source = 'admin';
        }
    }
}
