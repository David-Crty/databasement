<?php

use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Helper to create an agent and return it with a plain text token.
 *
 * @return array{agent: Agent, token: string}
 */
function createAgentWithToken(): array
{
    $agent = Agent::factory()->create();
    $token = $agent->createToken('agent');

    return ['agent' => $agent, 'token' => $token->plainTextToken];
}

describe('agent authentication', function () {
    test('unauthenticated requests are rejected', function () {
        $this->postJson('/api/v1/agent/heartbeat')
            ->assertUnauthorized();
    });

    test('user tokens are rejected by agent middleware', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/agent/heartbeat')
            ->assertForbidden();
    });

    test('agent tokens are accepted', function () {
        ['token' => $token] = createAgentWithToken();

        $this->withToken($token)
            ->postJson('/api/v1/agent/heartbeat')
            ->assertOk();
    });
});

describe('agent heartbeat', function () {
    test('updates last_heartbeat_at', function () {
        ['agent' => $agent, 'token' => $token] = createAgentWithToken();

        expect($agent->last_heartbeat_at)->toBeNull();

        $this->withToken($token)
            ->postJson('/api/v1/agent/heartbeat')
            ->assertOk();

        expect($agent->fresh()->last_heartbeat_at)->not->toBeNull();
    });
});

describe('job claiming', function () {
    test('can claim a pending job', function () {
        ['agent' => $agent, 'token' => $token] = createAgentWithToken();

        $server = DatabaseServer::factory()->create(['agent_id' => $agent->id]);
        $snapshot = Snapshot::factory()->forServer($server)->create();
        $agentJob = AgentJob::factory()->create(['snapshot_id' => $snapshot->id]);

        $response = $this->withToken($token)
            ->postJson('/api/v1/agent/jobs/claim')
            ->assertOk();

        $jobData = $response->json('job');
        expect($jobData)->not->toBeNull()
            ->and($jobData['id'])->toBe($agentJob->id);

        $agentJob->refresh();
        expect($agentJob->status)->toBe(AgentJob::STATUS_CLAIMED)
            ->and($agentJob->agent_id)->toBe($agent->id)
            ->and($agentJob->claimed_at)->not->toBeNull()
            ->and($agentJob->lease_expires_at)->not->toBeNull();

        // BackupJob should be marked as running with started_at set
        $backupJob = $snapshot->job->fresh();
        expect($backupJob->status)->toBe('running')
            ->and($backupJob->started_at)->not->toBeNull();
    });

    test('returns null when no jobs available', function () {
        ['token' => $token] = createAgentWithToken();

        $this->withToken($token)
            ->postJson('/api/v1/agent/jobs/claim')
            ->assertOk()
            ->assertJson(['job' => null]);
    });

    test('cannot claim jobs for servers not assigned to this agent', function () {
        ['token' => $token] = createAgentWithToken();

        // Job belongs to a server with no agent
        $server = DatabaseServer::factory()->create();
        $snapshot = Snapshot::factory()->forServer($server)->create();
        AgentJob::factory()->create(['snapshot_id' => $snapshot->id]);

        $this->withToken($token)
            ->postJson('/api/v1/agent/jobs/claim')
            ->assertOk()
            ->assertJson(['job' => null]);
    });

    test('can claim an expired lease job', function () {
        ['agent' => $agent, 'token' => $token] = createAgentWithToken();

        $server = DatabaseServer::factory()->create(['agent_id' => $agent->id]);
        $snapshot = Snapshot::factory()->forServer($server)->create();
        $agentJob = AgentJob::factory()->expiredLease()->create([
            'snapshot_id' => $snapshot->id,
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/agent/jobs/claim')
            ->assertOk()
            ->assertJsonPath('job.id', $agentJob->id);

        $agentJob->refresh();
        expect($agentJob->status)->toBe(AgentJob::STATUS_CLAIMED)
            ->and($agentJob->attempts)->toBe(2); // Was 1 from factory, now incremented
    });
});

describe('job heartbeat', function () {
    test('can extend lease', function () {
        ['agent' => $agent, 'token' => $token] = createAgentWithToken();
        // Create a job with a lease that expires in 1 minute (soon)
        $agentJob = AgentJob::factory()->create([
            'agent_id' => $agent->id,
            'status' => 'claimed',
            'claimed_at' => now(),
            'lease_expires_at' => now()->addMinute(),
            'attempts' => 1,
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/heartbeat")
            ->assertOk();

        $agentJob->refresh();
        // Lease should now be 5 minutes from now (config default), which is > 1 minute
        expect($agentJob->lease_expires_at->isAfter(now()->addMinutes(2)))->toBeTrue();
    });

    test('heartbeat appends logs to existing logs', function () {
        ['agent' => $agent, 'token' => $token] = createAgentWithToken();
        $agentJob = AgentJob::factory()->claimed($agent)->create();

        // First heartbeat with initial logs
        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/heartbeat", [
                'logs' => [['timestamp' => now()->toIso8601String(), 'type' => 'log', 'level' => 'info', 'message' => 'Dump started']],
            ]);

        // Second heartbeat with more logs
        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/heartbeat", [
                'logs' => [['timestamp' => now()->toIso8601String(), 'type' => 'log', 'level' => 'info', 'message' => 'Compression done']],
            ]);

        $backupJob = $agentJob->snapshot->job->fresh();
        expect($backupJob->logs)->toHaveCount(2)
            ->and($backupJob->logs[0]['message'])->toBe('Dump started')
            ->and($backupJob->logs[1]['message'])->toBe('Compression done');
    });
});

describe('job acknowledgement', function () {
    test('can ack a completed job', function () {
        ['agent' => $agent, 'token' => $token] = createAgentWithToken();
        $agentJob = AgentJob::factory()->claimed($agent)->create();

        $logs = [
            ['timestamp' => now()->toIso8601String(), 'type' => 'log', 'level' => 'info', 'message' => 'Starting backup for database: testdb'],
            ['timestamp' => now()->toIso8601String(), 'type' => 'log', 'level' => 'success', 'message' => 'Backup completed successfully'],
        ];

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/ack", [
                'filename' => 'backups/test-db-2026-02-20-120000.sql.gz',
                'file_size' => 12345,
                'checksum' => 'abc123sha256hash',
                'logs' => $logs,
            ])
            ->assertOk();

        $agentJob->refresh();
        expect($agentJob->status)->toBe(AgentJob::STATUS_COMPLETED)
            ->and($agentJob->completed_at)->not->toBeNull();

        $snapshot = $agentJob->snapshot->fresh();
        expect($snapshot->filename)->toBe('backups/test-db-2026-02-20-120000.sql.gz')
            ->and($snapshot->file_size)->toBe(12345)
            ->and($snapshot->checksum)->toBe('abc123sha256hash');

        // BackupJob should be completed with logs written
        $backupJob = $snapshot->job;
        expect($backupJob->status)->toBe('completed')
            ->and($backupJob->logs)->toHaveCount(2)
            ->and($backupJob->logs[0]['message'])->toBe('Starting backup for database: testdb');
    });
});

describe('job failure', function () {
    test('can report job failure', function () {
        ['agent' => $agent, 'token' => $token] = createAgentWithToken();
        $agentJob = AgentJob::factory()->claimed($agent)->create();

        $logs = [
            ['timestamp' => now()->toIso8601String(), 'type' => 'log', 'level' => 'info', 'message' => 'Starting backup for database: testdb'],
            ['timestamp' => now()->toIso8601String(), 'type' => 'log', 'level' => 'error', 'message' => 'Backup failed: Connection refused'],
        ];

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/fail", [
                'error_message' => 'Connection refused to database server',
                'logs' => $logs,
            ])
            ->assertOk();

        $agentJob->refresh();
        expect($agentJob->status)->toBe(AgentJob::STATUS_FAILED)
            ->and($agentJob->error_message)->toBe('Connection refused to database server')
            ->and($agentJob->completed_at)->not->toBeNull();

        // BackupJob should be failed with logs written
        $backupJob = $agentJob->snapshot->fresh()->job;
        expect($backupJob->status)->toBe('failed')
            ->and($backupJob->logs)->toHaveCount(2)
            ->and($backupJob->logs[1]['message'])->toBe('Backup failed: Connection refused');
    });

    test('fail sends failure notification for backup jobs', function () {
        Notification::fake();
        \App\Models\NotificationChannel::factory()->email()->create(['config' => ['to' => 'admin@example.com']]);

        ['agent' => $agent, 'token' => $token] = createAgentWithToken();
        $agentJob = AgentJob::factory()->claimed($agent)->create();

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/fail", [
                'error_message' => 'Connection refused',
            ])
            ->assertOk();

        Notification::assertSentTimes(\App\Notifications\BackupFailedNotification::class, 1);
    });

    test('failing a discovery job marks it failed without notification or backup job impact', function () {
        Notification::fake();

        ['agent' => $agent, 'token' => $token] = createAgentWithToken();
        $server = DatabaseServer::factory()->create(['agent_id' => $agent->id]);

        $agentJob = AgentJob::factory()->discover()->claimed($agent)->create([
            'database_server_id' => $server->id,
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/fail", [
                'error_message' => 'Cannot connect to database',
            ])
            ->assertOk();

        $agentJob->refresh();
        expect($agentJob->status)->toBe(AgentJob::STATUS_FAILED)
            ->and($agentJob->error_message)->toBe('Cannot connect to database');

        Notification::assertNothingSent();
    });
});

describe('log accumulation across heartbeat and terminal operations', function () {
    test('logs accumulate from heartbeat to terminal operation', function (string $endpoint, array $payload) {
        ['agent' => $agent, 'token' => $token] = createAgentWithToken();
        $agentJob = AgentJob::factory()->claimed($agent)->create();

        // Simulate progressive logs sent during heartbeats
        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/heartbeat", [
                'logs' => [
                    ['timestamp' => now()->toIso8601String(), 'type' => 'log', 'level' => 'info', 'message' => 'Dump started'],
                    ['timestamp' => now()->toIso8601String(), 'type' => 'log', 'level' => 'info', 'message' => 'Dump completed'],
                ],
            ]);

        // Terminal operation with final logs
        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/{$endpoint}", array_merge($payload, [
                'logs' => [
                    ['timestamp' => now()->toIso8601String(), 'type' => 'log', 'level' => 'info', 'message' => 'Final step'],
                ],
            ]))
            ->assertOk();

        $backupJob = $agentJob->snapshot->job->fresh();
        expect($backupJob->logs)->toHaveCount(3)
            ->and($backupJob->logs[0]['message'])->toBe('Dump started')
            ->and($backupJob->logs[1]['message'])->toBe('Dump completed')
            ->and($backupJob->logs[2]['message'])->toBe('Final step');
    })->with([
        'ack' => ['ack', ['filename' => 'backups/test.sql.gz', 'file_size' => 12345, 'checksum' => 'abc123']],
        'fail' => ['fail', ['error_message' => 'Connection lost']],
    ]);
});

describe('ownership checks', function () {
    test('cannot act on another agent job', function (string $endpoint, array $payload) {
        ['token' => $token] = createAgentWithToken();
        $otherAgent = Agent::factory()->create();
        $agentJob = AgentJob::factory()->claimed($otherAgent)->create();

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/{$endpoint}", $payload)
            ->assertForbidden();
    })->with([
        'heartbeat' => ['heartbeat', []],
        'ack' => ['ack', ['filename' => 'test.sql.gz', 'file_size' => 123]],
        'fail' => ['fail', ['error_message' => 'Error']],
    ]);

    test('cannot report discovered databases for another agent job', function () {
        ['token' => $token] = createAgentWithToken();
        $otherAgent = Agent::factory()->create();
        $server = DatabaseServer::factory()->create(['agent_id' => $otherAgent->id]);

        $agentJob = AgentJob::factory()->discover()->claimed($otherAgent)->create([
            'database_server_id' => $server->id,
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/discovered-databases", [
                'databases' => ['db1'],
            ])
            ->assertForbidden();
    });
});

describe('discovery jobs', function () {
    test('can claim a discovery job', function () {
        ['agent' => $agent, 'token' => $token] = createAgentWithToken();
        $server = DatabaseServer::factory()->create(['agent_id' => $agent->id]);

        $agentJob = AgentJob::factory()->discover()->create([
            'database_server_id' => $server->id,
        ]);

        $response = $this->withToken($token)
            ->postJson('/api/v1/agent/jobs/claim')
            ->assertOk();

        $jobData = $response->json('job');
        expect($jobData)->not->toBeNull()
            ->and($jobData['id'])->toBe($agentJob->id)
            ->and($jobData['snapshot_id'])->toBeNull()
            ->and($jobData['payload']['type'])->toBe('discover');
    });

    test('can report discovered databases', function () {
        ['agent' => $agent, 'token' => $token] = createAgentWithToken();

        $server = DatabaseServer::factory()->create([
            'agent_id' => $agent->id,
            'database_selection_mode' => 'all',
        ]);
        $server->load('backups.volume');
        $backup = $server->backups->first();

        $agentJob = AgentJob::factory()->discover()->claimed($agent)->create([
            'database_server_id' => $server->id,
            'payload' => ['type' => 'discover', 'backup_id' => $backup->id],
        ]);

        $response = $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/discovered-databases", [
                'databases' => ['db1', 'db2', 'db3'],
            ])
            ->assertOk();

        expect($response->json('jobs_created'))->toBe(3);

        // Discovery job should be completed
        $agentJob->refresh();
        expect($agentJob->status)->toBe(AgentJob::STATUS_COMPLETED);

        // 3 backup agent jobs should have been created
        $backupJobs = AgentJob::where('database_server_id', $server->id)
            ->where('type', AgentJob::TYPE_BACKUP)
            ->get();

        expect($backupJobs)->toHaveCount(3);

        $dbNames = Snapshot::whereIn('id', $backupJobs->pluck('snapshot_id'))
            ->pluck('database_name')
            ->sort()
            ->values()
            ->toArray();

        expect($dbNames)->toBe(['db1', 'db2', 'db3']);
    });

    test('discovered-databases rejects a discovery job whose payload has no backup_id', function () {
        ['agent' => $agent, 'token' => $token] = createAgentWithToken();

        $server = DatabaseServer::factory()->create(['agent_id' => $agent->id]);

        // Legacy payload (pre-multi-backup) with no backup_id — a stuck job
        // from before the upgrade. The controller must refuse to fabricate
        // snapshots with an unknown parent.
        $agentJob = AgentJob::factory()->discover()->claimed($agent)->create([
            'database_server_id' => $server->id,
            'payload' => ['type' => 'discover'],
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/discovered-databases", [
                'databases' => ['db1'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Backup configuration not found for this discovery job.');
    });

    test('discovered-databases rejects non-discovery jobs', function () {
        ['agent' => $agent, 'token' => $token] = createAgentWithToken();

        $agentJob = AgentJob::factory()->claimed($agent)->create();

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/discovered-databases", [
                'databases' => ['db1'],
            ])
            ->assertStatus(422);
    });

    test('discovered-databases rejects duplicate database names', function () {
        ['agent' => $agent, 'token' => $token] = createAgentWithToken();

        $server = DatabaseServer::factory()->create([
            'agent_id' => $agent->id,
            'database_selection_mode' => 'all',
        ]);
        $server->load('backups');
        $backup = $server->backups->first();

        $agentJob = AgentJob::factory()->discover()->claimed($agent)->create([
            'database_server_id' => $server->id,
            'payload' => ['type' => 'discover', 'backup_id' => $backup->id],
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/discovered-databases", [
                'databases' => ['db1', 'db2', 'db1'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['databases.2']);
    });
});
