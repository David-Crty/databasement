<?php

use App\Rules\SafeEndpointUrl;
use Illuminate\Support\Facades\Validator;

test('SafeEndpointUrl accepts or rejects endpoints', function (string $input, bool $valid) {
    $passes = Validator::make(
        ['endpoint' => $input],
        ['endpoint' => [new SafeEndpointUrl]],
    )->passes();

    expect($passes)->toBe($valid);
})->with([
    // Self-hosted object storage on a private network is a first-class use case.
    'private minio host' => ['http://minio:9000', true],
    'private rfc1918 address' => ['http://192.168.1.10:9000', true],
    'loopback' => ['http://127.0.0.1:9000', true],
    'public https endpoint' => ['https://s3.eu-west-3.amazonaws.com', true],

    // Cloud instance metadata is how an SSRF here turns into IAM credentials.
    'ec2 metadata address' => ['http://169.254.169.254/latest/meta-data/', false],
    'ec2 metadata ipv6' => ['http://[fd00:ec2::254]/latest/meta-data/', false],
    'ipv6 link local' => ['http://[fe80::1]/', false],
    'ipv4 mapped metadata address' => ['http://[::ffff:169.254.169.254]/', false],

    'non http scheme' => ['file:///etc/passwd', false],
    'gopher scheme' => ['gopher://127.0.0.1:6379/_INFO', false],
    'missing scheme' => ['s3.example.com', false],
]);

test('s3 volume creation rejects an instance metadata sts endpoint', function () {
    $user = App\Models\User::factory()->withAbilities([App\Enums\Ability::ManageVolumes->value])->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/volumes/s3', [
            'name' => 'ssrf-test',
            'config' => [
                'bucket' => 'anything',
                'region' => 'us-east-1',
                'custom_role_arn' => 'arn:aws:iam::000000000000:role/test',
                'sts_endpoint' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('config.sts_endpoint');
});
