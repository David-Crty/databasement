<?php

namespace App\Services\Backup\Filesystems;

use Aws\Credentials\AssumeRoleCredentialProvider;
use Aws\Credentials\CredentialProvider;
use Aws\S3\S3Client;
use Aws\Sts\StsClient;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;

class Awss3Filesystem implements FilesystemInterface
{
    private ?S3Client $client = null;

    public function handles(?string $type): bool
    {
        return in_array(strtolower($type ?? ''), ['s3', 'awss3']);
    }

    public function get(array $config): Filesystem
    {
        $client = $this->getClient();

        // Support both 'root' (from config/backup.php) and 'prefix' (from Volume database)
        $root = $config['root'] ?? $config['prefix'] ?? '';

        return new Filesystem(new AwsS3V3Adapter($client, $config['bucket'], $root));
    }

    /**
     * Generate a presigned URL for downloading a file from S3
     *
     * @param  array<string, mixed>  $config
     */
    public function getPresignedUrl(array $config, string $path, int $expiresInMinutes = 60): string
    {
        $client = $this->getClient();

        $command = $client->getCommand('GetObject', [
            'Bucket' => $config['bucket'],
            'Key' => $path,
        ]);

        $request = $client->createPresignedRequest($command, "+{$expiresInMinutes} minutes");

        return (string) $request->getUri();
    }

    private function getClient(): S3Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->client = $this->createClient();

        return $this->client;
    }

    private function createClient(): S3Client
    {
        /** @var array<string, mixed> $awsConfig */
        $awsConfig = config('aws');

        $clientConfig = [
            'version' => 'latest',
            'region' => $awsConfig['region'],
        ];

        if (! empty($awsConfig['s3_profile'])) {
            $clientConfig['profile'] = $awsConfig['s3_profile'];
        }

        // Use IAM role assumption if role_arn is configured
        if (! empty($awsConfig['custom_role_arn'])) {
            $clientConfig['credentials'] = $this->createCustomAssumeRoleCredentials($awsConfig);
        }

        if (! empty($awsConfig['s3_endpoint'])) {
            $clientConfig['endpoint'] = $awsConfig['s3_endpoint'];
        }

        if (! empty($awsConfig['use_path_style_endpoint'])) {
            $clientConfig['use_path_style_endpoint'] = true;
        }

        return new S3Client($clientConfig);
    }

    /**
     * Create credentials provider using IAM role assumption via STS
     *
     * @param  array<string, mixed>  $awsConfig
     */
    private function createCustomAssumeRoleCredentials(array $awsConfig): callable
    {
        $stsConfig = [
            'version' => 'latest',
            'region' => $awsConfig['region'],
        ];

        if (! empty($awsConfig['sts_profile'])) {
            $stsConfig['profile'] = $awsConfig['sts_profile'];
        }

        if (! empty($awsConfig['sts_endpoint'])) {
            $stsConfig['endpoint'] = $awsConfig['sts_endpoint'];
        }

        $stsClient = new StsClient($stsConfig);

        $assumeRoleProvider = new AssumeRoleCredentialProvider([
            'client' => $stsClient,
            'assume_role_params' => [
                'RoleArn' => $awsConfig['custom_role_arn'],
                'RoleSessionName' => $awsConfig['role_session_name'],
            ],
        ]);

        return CredentialProvider::memoize($assumeRoleProvider);
    }
}
