<?php

use App\Http\Controllers\Api\V1\BackupJobController;
use App\Http\Controllers\Api\V1\BackupScheduleController;
use App\Http\Controllers\Api\V1\DatabaseServerController;
use App\Http\Controllers\Api\V1\ScheduledRestoreController;
use App\Http\Controllers\Api\V1\SnapshotController;
use App\Http\Controllers\Api\V1\UserOrganizationController;
use App\Http\Controllers\Api\V1\VolumeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->name('api.')->prefix('v1')->group(function () {
    Route::apiResource('database-servers', DatabaseServerController::class)
        ->only(['index', 'show', 'store', 'destroy']);
    Route::put('database-servers/{database_server}', [DatabaseServerController::class, 'update'])
        ->name('database-servers.update');
    Route::get('database-servers/{database_server}/test-connection', [DatabaseServerController::class, 'testConnection'])
        ->name('database-servers.test-connection');
    Route::post('database-servers/{database_server}/backup', [DatabaseServerController::class, 'backup'])
        ->name('database-servers.backup');
    Route::post('database-servers/{database_server}/restore', [DatabaseServerController::class, 'restore'])
        ->name('database-servers.restore');

    Route::apiResource('jobs', BackupJobController::class)
        ->only(['index', 'show'])
        ->parameters(['jobs' => 'backupJob']);

    Route::apiResource('snapshots', SnapshotController::class)
        ->only(['index', 'show']);

    Route::apiResource('volumes', VolumeController::class)
        ->only(['index', 'show', 'destroy']);
    Route::post('volumes/local', [VolumeController::class, 'storeLocal'])->name('volumes.store.local');
    Route::post('volumes/s3', [VolumeController::class, 'storeS3'])->name('volumes.store.s3');
    Route::post('volumes/sftp', [VolumeController::class, 'storeSftp'])->name('volumes.store.sftp');
    Route::post('volumes/ftp', [VolumeController::class, 'storeFtp'])->name('volumes.store.ftp');
    Route::get('volumes/{volume}/test-connection', [VolumeController::class, 'testConnection'])->name('volumes.test-connection');

    Route::apiResource('backup-schedules', BackupScheduleController::class)
        ->only(['index', 'show', 'store', 'destroy']);
    Route::put('backup-schedules/{backup_schedule}', [BackupScheduleController::class, 'update'])
        ->name('backup-schedules.update');

    Route::apiResource('scheduled-restores', ScheduledRestoreController::class)
        ->only(['index', 'show', 'store', 'destroy']);
    Route::put('scheduled-restores/{scheduled_restore}', [ScheduledRestoreController::class, 'update'])
        ->name('scheduled-restores.update');
    Route::post('scheduled-restores/{scheduled_restore}/run', [ScheduledRestoreController::class, 'run'])
        ->name('scheduled-restores.run');

    Route::get('user/organizations', [UserOrganizationController::class, 'index'])
        ->name('user.organizations');
});
