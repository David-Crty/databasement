<?php

use App\Livewire\Volume\Create;
use App\Livewire\Volume\Edit;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\User;
use App\Models\Volume;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->withAbilities(['manage-volumes'])->create();
    $this->agent = Agent::factory()->create();
});

/**
 * @return array<string, mixed>
 */
function sftpFormState(Agent $agent): array
{
    return [
        'form.name' => 'Private NAS',
        'form.type' => 'sftp',
        'form.use_agent' => true,
        'form.agent_id' => $agent->id,
        'form.sftpConfig.host' => '10.0.0.9',
        'form.sftpConfig.port' => 22,
        'form.sftpConfig.username' => 'backup',
        'form.sftpConfig.password' => 'secret',
        'form.sftpConfig.root' => '/backups',
    ];
}

describe('binding a volume to an agent', function () {
    test('manage-volumes allows saving a volume bound to an agent', function () {
        Livewire::actingAs($this->user)
            ->test(Create::class)
            ->set(sftpFormState($this->agent))
            ->call('save');

        $volume = Volume::where('name', 'Private NAS')->sole();

        expect($volume->agent_id)->toBe($this->agent->id)
            ->and($volume->isRemote())->toBeTrue();
    });

    test('turning the toggle off unbinds the agent', function () {
        $volume = Volume::factory()->create(['agent_id' => $this->agent->id]);

        Livewire::actingAs($this->user)
            ->test(Edit::class, ['volume' => $volume])
            ->assertSet('form.use_agent', true)
            ->assertSet('form.agent_id', $this->agent->id)
            ->set('form.use_agent', false)
            ->call('save');

        expect($volume->fresh()->agent_id)->toBeNull();
    });

    test('an agent must be picked when the volume is marked as remote', function () {
        Livewire::actingAs($this->user)
            ->test(Create::class)
            ->set(sftpFormState($this->agent))
            ->set('form.agent_id', null)
            ->call('save')
            ->assertHasErrors('form.agent_id');
    });
});

describe('running the test on the agent', function () {
    test('testing a remote volume queues a job for its agent instead of probing locally', function () {
        $component = Livewire::actingAs($this->user)
            ->test(Create::class)
            ->set(sftpFormState($this->agent))
            ->call('testConnection');

        $job = AgentJob::where('type', AgentJob::TYPE_VOLUME_TEST)->sole();

        expect($job->agent_id)->toBe($this->agent->id)
            ->and($job->database_server_id)->toBeNull()
            ->and($job->max_attempts)->toBe(1)
            ->and($job->payload['volume']['type'])->toBe('sftp')
            ->and($job->payload['volume']['config']['host'])->toBe('10.0.0.9');

        $component->assertSet('form.connectionTestJobId', $job->id)
            ->assertSet('form.testingConnection', true)
            ->assertSet('form.connectionTestSuccess', false);
    });

    test('a local volume is still probed by the app, with no agent job', function () {
        $volume = Volume::factory()->create();

        Livewire::actingAs($this->user)
            ->test(Edit::class, ['volume' => $volume])
            ->call('testConnection')
            ->assertSet('form.connectionTestSuccess', true)
            ->assertSet('form.testingConnection', false)
            ->assertSet('form.connectionTestJobId', null);

        expect(AgentJob::where('type', AgentJob::TYPE_VOLUME_TEST)->exists())->toBeFalse();
    });

    test('the agent completing the job surfaces success in the form', function () {
        $component = Livewire::actingAs($this->user)
            ->test(Create::class)
            ->set(sftpFormState($this->agent))
            ->call('testConnection');

        AgentJob::where('type', AgentJob::TYPE_VOLUME_TEST)->sole()->markCompleted();

        $component->call('pollConnectionTest')
            ->assertSet('form.connectionTestSuccess', true)
            ->assertSet('form.testingConnection', false)
            ->assertSet('form.connectionTestJobId', null);
    });

    test('the agent failing the job surfaces its error message', function () {
        $component = Livewire::actingAs($this->user)
            ->test(Create::class)
            ->set(sftpFormState($this->agent))
            ->call('testConnection');

        AgentJob::where('type', AgentJob::TYPE_VOLUME_TEST)->sole()->markFailed('Permission denied on /backups');

        $component->call('pollConnectionTest')
            ->assertSet('form.connectionTestSuccess', false)
            ->assertSet('form.connectionTestJobId', null)
            ->assertSet('form.connectionTestMessage', 'Permission denied on /backups');
    });

    test('an agent that never picks the job up times out instead of polling forever', function () {
        config(['agent.volume_test_timeout' => 60]);

        $component = Livewire::actingAs($this->user)
            ->test(Create::class)
            ->set(sftpFormState($this->agent))
            ->call('testConnection');

        $job = AgentJob::where('type', AgentJob::TYPE_VOLUME_TEST)->sole();

        // Still waiting inside the window.
        $component->call('pollConnectionTest')
            ->assertSet('form.connectionTestJobId', $job->id);

        $this->travel(61)->seconds();

        $component->call('pollConnectionTest')
            ->assertSet('form.connectionTestSuccess', false)
            ->assertSet('form.connectionTestJobId', null);
    });
});

describe('claiming volume test jobs', function () {
    test('an agent can claim a volume test job that has no database server', function () {
        $agent = Agent::factory()->create();
        $token = $agent->createToken('agent')->plainTextToken;

        $job = AgentJob::factory()->volumeTest()->create(['agent_id' => $agent->id]);

        $this->withToken($token)
            ->postJson('/api/v1/agent/jobs/claim')
            ->assertOk()
            ->assertJsonPath('job.id', $job->id);
    });

    test('an agent cannot claim a volume test job targeted at another agent', function () {
        $other = Agent::factory()->create();
        $token = Agent::factory()->create()->createToken('agent')->plainTextToken;

        AgentJob::factory()->volumeTest()->create(['agent_id' => $other->id]);

        $this->withToken($token)
            ->postJson('/api/v1/agent/jobs/claim')
            ->assertOk()
            ->assertJson(['job' => null]);
    });
});

describe('the volume-tested endpoint', function () {
    test('a successful report completes the job', function () {
        $agent = Agent::factory()->create();
        $token = $agent->createToken('agent')->plainTextToken;
        $job = AgentJob::factory()->volumeTest()->claimed($agent)->create();

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$job->id}/volume-tested", [
                'success' => true,
                'message' => 'Connection successful!',
            ])
            ->assertOk();

        expect($job->fresh()->status)->toBe(AgentJob::STATUS_COMPLETED);
    });

    test('a failed report stores the reason on the job', function () {
        $agent = Agent::factory()->create();
        $token = $agent->createToken('agent')->plainTextToken;
        $job = AgentJob::factory()->volumeTest()->claimed($agent)->create();

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$job->id}/volume-tested", [
                'success' => false,
                'message' => 'Connection refused',
            ])
            ->assertOk();

        $job->refresh();

        expect($job->status)->toBe(AgentJob::STATUS_FAILED)
            ->and($job->error_message)->toBe('Connection refused');
    });

    test('rejects a job that is not a volume test job', function () {
        $agent = Agent::factory()->create();
        $token = $agent->createToken('agent')->plainTextToken;
        $job = AgentJob::factory()->claimed($agent)->create();

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$job->id}/volume-tested", ['success' => true])
            ->assertStatus(422);
    });
});
