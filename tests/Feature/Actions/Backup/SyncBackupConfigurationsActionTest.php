<?php

use App\Actions\Backup\SyncBackupConfigurationsAction;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Volume;
use Illuminate\Validation\ValidationException;

/**
 * @return array<string, mixed>
 */
function backupEntry(array $overrides = []): array
{
    return [
        'database_selection_mode' => 'all',
        'volume_ids' => [Volume::factory()->local()->create()->id],
        'backup_schedule_id' => dailySchedule()->id,
        'retention_policy' => Backup::RETENTION_FOREVER,
        ...$overrides,
    ];
}

test('an entry with an id updates that configuration and one without creates another', function () {
    $server = DatabaseServer::factory()->create(['database_type' => 'postgres', 'backups_enabled' => true]);
    $existing = $server->backups->first();
    $volume = Volume::factory()->local()->create();

    SyncBackupConfigurationsAction::run($server, [
        backupEntry(['id' => $existing->id, 'retention_policy' => Backup::RETENTION_DAYS, 'retention_days' => 7]),
        backupEntry(['volume_ids' => [$volume->id]]),
    ]);

    $backups = $server->backups()->with('volumes')->get();
    expect($backups)->toHaveCount(2)
        ->and($backups->firstWhere('id', $existing->id)->retention_days)->toBe(7)
        ->and($backups->firstWhere('id', '!=', $existing->id)->volumes->pluck('id')->all())->toBe([$volume->id]);
});

test('a configuration missing from the entries is deleted', function () {
    $server = DatabaseServer::factory()->create(['database_type' => 'postgres', 'backups_enabled' => true]);
    $removed = $server->backups->first();

    SyncBackupConfigurationsAction::run($server, [backupEntry()]);

    expect(Backup::find($removed->id))->toBeNull()
        ->and($server->backups()->count())->toBe(1);
});

test('an id belonging to another server is rejected before anything changes', function () {
    $server = DatabaseServer::factory()->create(['database_type' => 'postgres', 'backups_enabled' => true]);
    $foreign = DatabaseServer::factory()->create(['database_type' => 'postgres', 'backups_enabled' => true])->backups->first();
    $kept = $server->backups->first();

    expect(fn () => SyncBackupConfigurationsAction::run($server, [backupEntry(['id' => $foreign->id])]))
        ->toThrow(ValidationException::class, 'does not belong to this database server');

    expect($server->backups()->pluck('id')->all())->toBe([$kept->id]);
});

test('nothing changes on a server whose backups are disabled', function () {
    $server = DatabaseServer::factory()->create(['database_type' => 'postgres', 'backups_enabled' => false]);
    $kept = $server->backups->first();

    SyncBackupConfigurationsAction::run($server, []);

    expect($server->backups()->pluck('id')->all())->toBe([$kept->id]);
});
