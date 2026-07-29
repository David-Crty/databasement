<?php

namespace App\Livewire\DatabaseServer\Connection;

use App\Livewire\DatabaseServer\Form;

class PostgresConnectionRules extends ClientServerConnectionRules
{
    public function extraConfig(Form $form): array
    {
        return $form->ssl_enabled ? ['ssl_enabled' => true] : [];
    }

    public function dumpPreviewConfig(Form $form): array
    {
        return [
            'dump_format' => $form->dump_format,
            'dump_privileges' => $form->dump_privileges,
            'ssl_enabled' => $form->ssl_enabled,
        ];
    }
}
