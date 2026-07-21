<?php

namespace App\Livewire\Forms\Connection;

use App\Livewire\Forms\DatabaseServerForm;

class PostgresConnectionRules extends ClientServerConnectionRules
{
    public function dumpPreviewConfig(DatabaseServerForm $form): array
    {
        return [
            'dump_format' => $form->dump_format,
            'dump_privileges' => $form->dump_privileges,
        ];
    }
}
