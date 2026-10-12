<?php

use App\Actions\DatabaseServer\CreateDatabaseServerAction;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Volume;
use App\Services\CurrentOrganization;
use Illuminate\Validation\ValidationException;

/**
 * @return array<string, mixed>
 */
function postgresServerAttributes(array $overrides = []): array
{
    return [
        'name' => 'Analytics',
        'database_type' => 'postgres',
        'host' => 'db.internal',
        'port' => 5432,
        'username' => 'backup',
        'password' => 'secret',
        ...$overrides,
    ];
}

test('it creates the server with its ssh tunnel, backups and notification channels', function () {
    $organization = app(CurrentOrganization::class)->model();
    $volume = Volume::factory()->local()->create();
    $channel = NotificationChannel::factory()->create();

    $server = CreateDatabaseServerAction::run(
        $organization,
        postgresServerAttributes(),
        backups: [[
            'database_selection_mode' => 'all',
            'volume_ids' => [$volume->id],
            'backup_schedule_id' => dailySchedule()->id,
            'retention_policy' => 'forever',
        ]],
        sshConfig: ['host' => 'bastion.example.com', 'username' => 'tunnel', 'auth_type' => 'password', 'password' => 'ssh-secret'],
        notificationChannelIds: [$channel->id],
    );

    expect($server->fresh())
        ->organization_id->toBe($organization->id)
        ->backups_enabled->toBeTrue()
        ->and($server->sshConfig->host)->toBe('bastion.example.com')
        ->and($server->sshConfig->organization_id)->toBe($organization->id)
        ->and($server->backups()->sole()->volumes->pluck('id')->all())->toBe([$volume->id])
        ->and($server->notificationChannels->pluck('id')->all())->toBe([$channel->id]);
});

test('a rejected backup configuration leaves no server and no ssh configuration behind', function () {
    $foreignBackup = DatabaseServer::factory()->create()->backups->first();
    $servers = DatabaseServer::count();
    $sshConfigs = DatabaseServerSshConfig::count();

    expect(fn () => CreateDatabaseServerAction::run(
        Organization::factory()->create(),
        postgresServerAttributes(),
        backups: [['id' => $foreignBackup->id]],
        sshConfig: ['host' => 'bastion.example.com', 'username' => 'tunnel', 'auth_type' => 'password', 'password' => 'ssh-secret'],
    ))->toThrow(ValidationException::class);

    expect(DatabaseServer::count())->toBe($servers)
        ->and(DatabaseServerSshConfig::count())->toBe($sshConfigs);
});
