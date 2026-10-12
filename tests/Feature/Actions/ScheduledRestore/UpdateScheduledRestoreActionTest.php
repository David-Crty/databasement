<?php

use App\Actions\ScheduledRestore\UpdateScheduledRestoreAction;
use Illuminate\Validation\ValidationException;

test('it changes only the given columns', function () {
    $scheduledRestore = createScheduledRestore(['options' => ['force_database' => true]]);

    UpdateScheduledRestoreAction::run($scheduledRestore, ['name' => 'Weekly refresh']);

    expect($scheduledRestore->fresh())
        ->name->toBe('Weekly refresh')
        ->options->toBe(['force_database' => true]);
});

test('it normalizes the options it is given', function () {
    $scheduledRestore = createScheduledRestore(['options' => ['force_database' => true]]);

    UpdateScheduledRestoreAction::run($scheduledRestore, ['options' => ['parallel_restore' => true, 'owner_user' => '']]);

    expect($scheduledRestore->fresh()->options)->toBe(['parallel_restore' => true]);
});

test('moving a scheduled restore onto the application database is rejected', function () {
    $scheduledRestore = createScheduledRestore();
    makeAppDatabaseServer($scheduledRestore->targetServer, 'databasement_app');

    expect(fn () => UpdateScheduledRestoreAction::run($scheduledRestore, ['schema_name' => 'databasement_app']))
        ->toThrow(ValidationException::class, 'Cannot restore over the application database.');

    expect($scheduledRestore->fresh()->schema_name)->toBe('restored_db');
});
