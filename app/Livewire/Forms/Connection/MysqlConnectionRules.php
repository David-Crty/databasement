<?php

namespace App\Livewire\Forms\Connection;

use App\Livewire\Forms\DatabaseServerForm;

class MysqlConnectionRules extends ClientServerConnectionRules
{
    public function extraConfig(DatabaseServerForm $form): array
    {
        return $form->ssl_enabled ? ['ssl_enabled' => true] : [];
    }

    public function dumpPreviewConfig(DatabaseServerForm $form): array
    {
        return ['ssl_enabled' => $form->ssl_enabled];
    }
}
