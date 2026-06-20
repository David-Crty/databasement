<?php

use App\Services\Backup\DTO\VolumeConfig;

test('toPayload serializes volume config', function () {
    $config = new VolumeConfig(
        type: 's3',
        name: 'Production Bucket',
        config: ['bucket' => 'my-backups', 'region' => 'us-east-1'],
    );

    expect($config->toPayload())->toBe([
        'type' => 's3',
        'name' => 'Production Bucket',
        'config' => ['bucket' => 'my-backups', 'region' => 'us-east-1'],
    ]);
});

test('fromPayload reconstructs volume config', function () {
    $config = VolumeConfig::fromPayload([
        'type' => 'local',
        'name' => 'Local Storage',
        'config' => ['path' => '/backups'],
    ]);

    expect($config->type)->toBe('local')
        ->and($config->name)->toBe('Local Storage')
        ->and($config->config)->toBe(['path' => '/backups']);
});

test('fromPayload uses defaults for missing fields', function () {
    $config = VolumeConfig::fromPayload([
        'type' => 's3',
    ]);

    expect($config->name)->toBe('Remote Volume')
        ->and($config->config)->toBe([]);
});
