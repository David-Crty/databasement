<?php

use App\Facades\AppConfig;
use App\Support\FilesystemSupport;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->beforeEach(function () {
        $this->withoutVite();
        dailySchedule();
        setupOrgContext();
    })
    ->in('Feature');

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->beforeEach(function () {
        $this->withoutVite();
        dailySchedule();
        setupOrgContext();
    })
    ->group('integration')
    ->in('Integration');

/*
|--------------------------------------------------------------------------
| Global Cleanup
|--------------------------------------------------------------------------
|
| Clean up temporary directories after each test to ensure no leftover files.
| This covers both the backup working directory and volume temp directories.
|
*/

afterEach(function () {
    // Clean up backup working directory (preserves the directory itself)
    $workingDirectory = AppConfig::get('backup.working_directory');
    if ($workingDirectory && is_dir($workingDirectory)) {
        FilesystemSupport::cleanupDirectory($workingDirectory, preserve: true);
    }

    // Clean up temp directories created during tests
    $tempDir = sys_get_temp_dir();
    $patterns = [
        '/volume-test-*',        // VolumeFactory
        '/backup-task-test-*',   // BackupTaskTest
        '/restore-task-test-*',  // RestoreTaskTest
        '/sqlite-db-test-*',    // SqliteDatabaseTest
    ];

    foreach ($patterns as $pattern) {
        $dirs = glob($tempDir.$pattern);
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                FilesystemSupport::cleanupDirectory($dir);
            }
        }
    }
});

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create the main organization and set it as the current org context.
 * This ensures OrganizationScope and CurrentOrganization work in tests.
 */
function setupOrgContext(): \App\Models\Organization
{
    $org = \App\Models\Organization::firstOrCreate(
        ['is_default' => true],
        ['name' => 'Default'],
    );

    // The global built-in Bouncer roles (and their abilities) are seeded by
    // migration, so they already exist here for ability checks to resolve.

    $currentOrg = app(\App\Services\CurrentOrganization::class);
    $currentOrg->set($org);

    // Component/unit tests don't run the ScopeBouncer middleware, so set the
    // scope here. Disable caching for deterministic checks; the dedicated
    // "takes effect without redeploy" test re-enables it to verify refresh().
    \Silber\Bouncer\BouncerFacade::dontCache();
    \App\Support\BouncerScope::apply($org->id);

    return $org;
}

/**
 * Add a user to an organization with the given role, assigned as a scoped
 * Bouncer role (replaces the old organization_user.role pivot in test setup).
 */
function attachUserToOrg(
    \App\Models\User $user,
    \App\Models\Organization $org,
    string $role = 'member',
): void {
    if (! $user->organizations()->where('organization_id', $org->id)->exists()) {
        $user->organizations()->attach($org->id);
    }

    app(\App\Services\Roles\AssignRoleToUserAction::class)->execute($user, $role, $org);
}

function dailySchedule(): \App\Models\BackupSchedule
{
    return \App\Models\BackupSchedule::firstOrCreate(
        ['name' => 'Daily'],
        ['expression' => '0 2 * * *'],
    );
}

function weeklySchedule(): \App\Models\BackupSchedule
{
    return \App\Models\BackupSchedule::firstOrCreate(
        ['name' => 'Weekly'],
        ['expression' => '0 3 * * 0'],
    );
}

/**
 * Create a DatabaseServer with its associated Backup and Volume via factory.
 *
 * @param  array<string, mixed>  $attributes
 */
function createDatabaseServer(array $attributes = []): \App\Models\DatabaseServer
{
    return \App\Models\DatabaseServer::factory()
        ->create($attributes)
        ->load('backups.volume');
}

/**
 * Create a matching source + target DatabaseServer pair of the same type.
 *
 * @return array{0: \App\Models\DatabaseServer, 1: \App\Models\DatabaseServer}
 */
function createRestoreServerPair(string $type = 'mysql'): array
{
    return [
        \App\Models\DatabaseServer::factory()->create(['database_type' => $type, 'database_names' => ['app']]),
        \App\Models\DatabaseServer::factory()->create(['database_type' => $type, 'database_names' => ['target']]),
    ];
}

/**
 * Create a ScheduledRestore with sensible MySQL defaults.
 *
 * Pass 'source' and/or 'target' DatabaseServer models to reuse existing
 * servers; all other keys are forwarded to the factory.
 *
 * @param  array<string, mixed>  $attrs
 */
function createScheduledRestore(array $attrs = []): \App\Models\ScheduledRestore
{
    $source = $attrs['source'] ?? \App\Models\DatabaseServer::factory()->create(['database_type' => 'mysql', 'database_names' => ['app']]);
    $target = $attrs['target'] ?? \App\Models\DatabaseServer::factory()->create(['database_type' => 'mysql', 'database_names' => ['target']]);

    return \App\Models\ScheduledRestore::factory()->create(array_merge([
        'source_server_id' => $source->id,
        'target_server_id' => $target->id,
        'source_database_name' => 'app',
        'schema_name' => 'restored_db',
    ], array_diff_key($attrs, ['source' => null, 'target' => null])));
}

/*
|--------------------------------------------------------------------------
| Datasets
|--------------------------------------------------------------------------
|
| Shared datasets that can be reused across multiple test files.
|
*/

dataset('database types', ['mysql', 'postgres', 'sqlite', 'redis', 'mongodb']);

dataset('database server configs', [
    'mysql' => [[
        'type' => 'mysql',
        'name' => 'MySQL Server',
        'host' => 'mysql.example.com',
        'port' => 3306,
    ]],
    'postgres' => [[
        'type' => 'postgres',
        'name' => 'PostgreSQL Server',
        'host' => 'postgres.example.com',
        'port' => 5432,
    ]],
    'sqlite' => [[
        'type' => 'sqlite',
        'name' => 'SQLite Database',
        'database_names' => ['/data/app.sqlite'],
    ]],
    'redis' => [[
        'type' => 'redis',
        'name' => 'Redis Server',
        'host' => 'redis.example.com',
        'port' => 6379,
    ]],
    'mongodb' => [[
        'type' => 'mongodb',
        'name' => 'MongoDB Server',
        'host' => 'mongodb.example.com',
        'port' => 27017,
    ]],
]);

dataset('retention policies', [
    'days' => [[
        'policy' => 'days',
        'form_fields' => ['form.backups.0.retention_days' => 30],
        'expected_backup' => [
            'retention_policy' => 'days',
            'retention_days' => 30,
            'gfs_keep_daily' => null,
            'gfs_keep_weekly' => null,
            'gfs_keep_monthly' => null,
        ],
    ]],
    'gfs' => [[
        'policy' => 'gfs',
        'form_fields' => [
            'form.backups.0.gfs_keep_daily' => 7,
            'form.backups.0.gfs_keep_weekly' => 4,
            'form.backups.0.gfs_keep_monthly' => 12,
        ],
        'expected_backup' => [
            'retention_policy' => 'gfs',
            'retention_days' => null,
            'gfs_keep_daily' => 7,
            'gfs_keep_weekly' => 4,
            'gfs_keep_monthly' => 12,
        ],
    ]],
    'forever' => [[
        'policy' => 'forever',
        'form_fields' => [],
        'expected_backup' => [
            'retention_policy' => 'forever',
            'retention_days' => null,
            'gfs_keep_daily' => null,
            'gfs_keep_weekly' => null,
            'gfs_keep_monthly' => null,
        ],
    ]],
]);
