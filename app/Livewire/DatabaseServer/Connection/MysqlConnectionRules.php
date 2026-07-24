<?php

namespace App\Livewire\DatabaseServer\Connection;

use App\Livewire\DatabaseServer\Form;

class MysqlConnectionRules extends ClientServerConnectionRules
{
    public function extraConfig(Form $form): array
    {
        return $form->ssl_enabled ? ['ssl_enabled' => true] : [];
    }

    public function dumpPreviewConfig(Form $form): array
    {
        return ['ssl_enabled' => $form->ssl_enabled];
    }
}
