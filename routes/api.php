<?php

use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\BackupJobController;
use App\Http\Controllers\Api\V1\BackupScheduleController;
use App\Http\Controllers\Api\V1\DatabaseServerController;
use App\Http\Controllers\Api\V1\DatabaseServerSshConfigController;
use App\Http\Controllers\Api\V1\ScheduledRestoreController;
use App\Http\Controllers\Api\V1\SnapshotController;
use App\Http\Controllers\Api\V1\UserOrganizationController;
use App\Http\Controllers\Api\V1\VolumeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->name('api.')->prefix('v1')->group(function () {
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

    Route::apiResource('database-server-ssh-configs', DatabaseServerSshConfigController::class)
        ->only(['index', 'show', 'store', 'destroy']);
    Route::put('database-server-ssh-configs/{database_server_ssh_config}', [DatabaseServerSshConfigController::class, 'update'])
        ->name('database-server-ssh-configs.update');

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

// Agent API routes — authenticated via Sanctum with agent-specific token check.
// Deliberately left off `throttle:api`: an agent polls heartbeat + claim every
// DATABASEMENT_AGENT_POLL_INTERVAL seconds (2 req/tick, so 120 req/min at the
// minimum interval of 1s) and does not sleep while jobs are queued. A 429 on
// ack would also lose the result of a backup that already uploaded.
// Failed-auth attempts are throttled separately by `throttle-failed-agent-auth`.
Route::middleware(['throttle-failed-agent-auth', 'auth:sanctum', 'agent'])->name('api.agent.')->prefix('v1/agent')->group(function () {
    Route::post('heartbeat', [AgentController::class, 'heartbeat'])->name('heartbeat');
    Route::post('jobs/claim', [AgentController::class, 'claimJob'])->name('jobs.claim');
    Route::post('jobs/{agentJob}/heartbeat', [AgentController::class, 'jobHeartbeat'])->name('jobs.heartbeat');
    Route::post('jobs/{agentJob}/ack', [AgentController::class, 'ack'])->name('jobs.ack');
    Route::post('jobs/{agentJob}/fail', [AgentController::class, 'fail'])->name('jobs.fail');
    Route::post('jobs/{agentJob}/discovered-databases', [AgentController::class, 'discoveredDatabases'])->name('jobs.discovered-databases');
});
