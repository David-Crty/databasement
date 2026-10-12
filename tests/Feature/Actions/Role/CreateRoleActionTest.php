<?php

use App\Actions\Role\CreateRoleAction;
use App\Enums\Ability;

test('it creates a global role with its abilities', function () {
    $role = CreateRoleAction::run('backup-operator', 'Backup Operator', [Ability::RunBackups->value, Ability::ManageVolumes->value]);

    expect($role->fresh())
        ->name->toBe('backup-operator')
        ->title->toBe('Backup Operator')
        ->scope->toBeNull()
        ->and($role->abilities->pluck('name')->sort()->values()->all())->toBe(['manage-volumes', 'run-backups']);
});
