<?php

namespace App\Livewire\Forms\Connection;

use App\Livewire\Forms\DatabaseServerForm;

/**
 * SQLite has no host/port/credentials — databases are file paths collected on
 * the backup cards. The connection test only validates the SSH tunnel fields
 * (when enabled) for remote-file access; the path requirement itself is
 * enforced generically for path-identified types in DatabaseServerForm.
 */
class SqliteConnectionRules extends ConnectionRules
{
    public function rules(DatabaseServerForm $form): array
    {
        return [];
    }

    public function testConnectionRules(DatabaseServerForm $form): array
    {
        return $form->ssh_enabled ? $form->getSshValidationRules() : [];
    }
}
