<?php

namespace App\Livewire\DatabaseServer\Connection;

use App\Livewire\DatabaseServer\Form;

/**
 * SQLite has no host/port/credentials — databases are file paths collected on
 * the backup cards. The connection test only validates the SSH tunnel fields
 * (when enabled) for remote-file access; the path requirement itself is
 * enforced generically for path-identified types in Form.
 */
class SqliteConnectionRules extends ConnectionRules
{
    public function rules(Form $form): array
    {
        return [];
    }

    public function testConnectionRules(Form $form): array
    {
        return $form->ssh_enabled ? $form->getSshValidationRules() : [];
    }
}
