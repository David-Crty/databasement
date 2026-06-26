<?php

use App\Models\DatabaseServer;
use App\Models\ScheduledRestore;
use App\Models\Snapshot;
use App\Models\User;

describe('operator role', function () {
    test('operator can run backup, restore, download and restore-from', function () {
        $operator = User::factory()->operator()->create();
        $server = DatabaseServer::factory()->create(['database_type' => 'mysql']);
        $snapshot = Snapshot::factory()->forServer($server)->create();

        expect($operator->can('backup', $server))->toBeTrue()
            ->and($operator->can('restore', $server))->toBeTrue()
            ->and($operator->can('download', $snapshot))->toBeTrue()
            ->and($operator->can('restoreFrom', $snapshot))->toBeTrue()
            ->and($operator->can('create', \App\Models\Restore::class))->toBeTrue();
    });

    test('operator cannot edit server configuration', function () {
        $operator = User::factory()->operator()->create();
        $server = DatabaseServer::factory()->create();

        expect($operator->can('create', DatabaseServer::class))->toBeFalse()
            ->and($operator->can('update', $server))->toBeFalse()
            ->and($operator->can('delete', $server))->toBeFalse()
            ->and($operator->can('viewForm', $server))->toBeFalse();
    });

    test('operator cannot delete snapshots', function () {
        $operator = User::factory()->operator()->create();
        $server = DatabaseServer::factory()->create();
        $snapshot = Snapshot::factory()->forServer($server)->create();

        expect($operator->can('delete', $snapshot))->toBeFalse();
    });

    test('viewer still cannot run backup, restore or download', function () {
        $viewer = User::factory()->viewer()->create();
        $server = DatabaseServer::factory()->create(['database_type' => 'mysql']);
        $snapshot = Snapshot::factory()->forServer($server)->create();

        expect($viewer->can('backup', $server))->toBeFalse()
            ->and($viewer->can('restore', $server))->toBeFalse()
            ->and($viewer->can('download', $snapshot))->toBeFalse()
            ->and($viewer->can('restoreFrom', $snapshot))->toBeFalse();
    });
});

test('operator can manage scheduled restores', function () {
    $operator = User::factory()->operator()->create();
    [$source, $target] = createRestoreServerPair('mysql');
    $scheduledRestore = createScheduledRestore(['source' => $source, 'target' => $target]);

    expect($operator->can('create', ScheduledRestore::class))->toBeTrue()
        ->and($operator->can('run', $scheduledRestore))->toBeTrue();
});
