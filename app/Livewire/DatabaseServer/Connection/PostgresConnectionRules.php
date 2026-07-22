<?php

namespace App\Livewire\DatabaseServer\Connection;

use App\Livewire\DatabaseServer\Form;

class PostgresConnectionRules extends ClientServerConnectionRules
{
    public function dumpPreviewConfig(Form $form): array
    {
        return [
            'dump_format' => $form->dump_format,
            'dump_privileges' => $form->dump_privileges,
        ];
    }
}
