<?php

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
    ->in('Feature');

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
    $workingDirectory = config('backup.working_directory');
    if ($workingDirectory && is_dir($workingDirectory)) {
        FilesystemSupport::cleanupDirectory($workingDirectory, preserve: true);
    }

    // Clean up temp directories created during tests
    $tempDir = sys_get_temp_dir();
    $patterns = [
        '/volume-test-*',        // VolumeFactory
        '/backup-task-test-*',   // BackupTaskTest
        '/restore-task-test-*',  // RestoreTaskTest
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

/*
|--------------------------------------------------------------------------
| Datasets
|--------------------------------------------------------------------------
|
| Shared datasets that can be reused across multiple test files.
|
*/

dataset('retention policies', [
    'days' => [[
        'policy' => 'days',
        'form_fields' => ['form.retention_days' => 30],
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
            'form.gfs_keep_daily' => 7,
            'form.gfs_keep_weekly' => 4,
            'form.gfs_keep_monthly' => 12,
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
