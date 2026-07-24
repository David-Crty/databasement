<?php

use App\Jobs\DeleteOrganizationJob;
use App\Models\DatabaseServer;
use App\Models\Organization;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use App\Services\CurrentOrganization;
use App\Services\Organization\OrganizationMergeService;
use Illuminate\Support\Facades\DB;

/**
 * Build a server + local volume + snapshot with a real backup file inside the
 * given organization. Returns the on-disk path of the backup file.
 */
function seedOrganizationSnapshot(Organization $org): string
{
    $volume = Volume::factory()->local()->create(['organization_id' => $org->id]);

    $backupFilename = 'org-delete-test.sql.gz';
    $backupFilePath = $volume->config['path'].'/'.$backupFilename;
    file_put_contents($backupFilePath, 'test backup content');

    $server = DatabaseServer::factory()->create([
        'organization_id' => $org->id,
        'database_names' => ['test_db'],
    ]);

    $backup = $server->backups->first();
    $backup->update(['volume_id' => $volume->id]);
    // Preload the org-scoped relations so the factory resolves them regardless
    // of the resolved organization context during the test.
    $backup->setRelation('databaseServer', $server);
    $backup->setRelation('volume', $volume);

    $snapshot = app(BackupJobFactory::class)->createSnapshots($backup, 'manual')[0];
    $snapshot->update(['filename' => $backupFilename, 'file_size' => filesize($backupFilePath)]);
    $snapshot->job->markCompleted();

    // The queue worker runs with no resolved organization (CLI context), so the
    // cascade resolves org-scoped relations without filtering. Mirror that here.
    app(CurrentOrganization::class)->reset();

    return $backupFilePath;
}

test('job deletes backup files when cascading organization deletion', function () {
    User::factory()->create();
    $org = Organization::factory()->create();
    $backupFilePath = seedOrganizationSnapshot($org);

    (new DeleteOrganizationJob($org->id))->handle(app(OrganizationMergeService::class));

    expect(Organization::find($org->id))->toBeNull()
        ->and(file_exists($backupFilePath))->toBeFalse('Backup file should be deleted from storage');
});

test('job preserves backup files when keepFiles is set', function () {
    User::factory()->create();
    $org = Organization::factory()->create();
    $backupFilePath = seedOrganizationSnapshot($org);

    (new DeleteOrganizationJob($org->id, null, keepFiles: true))->handle(app(OrganizationMergeService::class));

    expect(Organization::find($org->id))->toBeNull()
        ->and(file_exists($backupFilePath))->toBeTrue('Backup file should be preserved on storage');
});

test('job removes memberships but keeps the user accounts', function () {
    $org = Organization::factory()->create();
    $member = User::factory()->create();
    attachUserToOrg($member, $org, 'member');

    (new DeleteOrganizationJob($org->id))->handle(app(OrganizationMergeService::class));

    expect(Organization::find($org->id))->toBeNull()
        ->and(User::find($member->id))->not->toBeNull('User account should survive org deletion')
        ->and(DB::table('organization_user')->where('organization_id', $org->id)->exists())
        ->toBeFalse('Membership pivot rows should be removed');
});
