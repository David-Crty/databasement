<?php

use App\Actions\ScheduledRestore\CreateScheduledRestoreAction;
use App\Models\DatabaseServer;
use Illuminate\Validation\ValidationException;

/**
 * @return array<string, mixed>
 */
function scheduledRestoreAttributes(DatabaseServer $source, DatabaseServer $target, array $overrides = []): array
{
    return [
        'name' => 'Nightly',
        'source_server_id' => $source->id,
        'source_database_name' => 'app',
        'target_server_id' => $target->id,
        'schema_name' => 'restored_db',
        'backup_schedule_id' => dailySchedule()->id,
        ...$overrides,
    ];
}

test('it creates a scheduled restore with its options stored without empty values', function () {
    [$source, $target] = createRestoreServerPair();

    $scheduledRestore = CreateScheduledRestoreAction::run(scheduledRestoreAttributes($source, $target, [
        'options' => ['force_database' => false, 'owner_user' => ' app_owner '],
    ]));

    expect($scheduledRestore->fresh())
        ->target_server_id->toBe($target->id)
        ->schema_name->toBe('restored_db')
        ->options->toBe(['owner_user' => 'app_owner']);
});

test('options left empty are not stored', function () {
    [$source, $target] = createRestoreServerPair();

    $scheduledRestore = CreateScheduledRestoreAction::run(scheduledRestoreAttributes($source, $target, ['options' => ['force_database' => false]]));

    expect($scheduledRestore->fresh()->options)->toBeNull();
});

test('a scheduled restore over the application database is rejected', function () {
    [$source, $target] = createRestoreServerPair();
    makeAppDatabaseServer($target, 'databasement_app');

    expect(fn () => CreateScheduledRestoreAction::run(scheduledRestoreAttributes($source, $target, ['schema_name' => 'databasement_app'])))
        ->toThrow(ValidationException::class, 'Cannot restore over the application database.');
});
