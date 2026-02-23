<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migrate S3 volumes to store all AWS config per-volume instead of globally via env.
     */
    public function up(): void
    {
        $region = env('AWS_REGION', 'us-east-1');
        $accessKeyId = env('AWS_ACCESS_KEY_ID', '');
        $secretAccessKey = env('AWS_SECRET_ACCESS_KEY', '');
        $customEndpoint = env('AWS_ENDPOINT_URL_S3', '');
        $publicEndpoint = env('AWS_PUBLIC_ENDPOINT_URL_S3', '');
        $usePathStyleEndpoint = (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false);
        $customRoleArn = env('AWS_CUSTOM_ROLE_ARN', '');
        $roleSessionName = env('AWS_ROLE_SESSION_NAME', '');
        $stsEndpoint = env('AWS_ENDPOINT_URL_STS', '');

        $volumes = DB::table('volumes')->where('type', 's3')->get();

        foreach ($volumes as $volume) {
            $config = json_decode($volume->config, true) ?? [];

            $config['region'] = $region;
            $config['access_key_id'] = $accessKeyId;
            $config['secret_access_key'] = ! empty($secretAccessKey) ? Crypt::encryptString($secretAccessKey) : '';
            $config['custom_endpoint'] = $customEndpoint;
            $config['public_endpoint'] = $publicEndpoint;
            $config['use_path_style_endpoint'] = $usePathStyleEndpoint;
            $config['custom_role_arn'] = $customRoleArn;
            $config['role_session_name'] = $roleSessionName;
            $config['sts_endpoint'] = $stsEndpoint;

            DB::table('volumes')
                ->where('id', $volume->id)
                ->update(['config' => json_encode($config)]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $volumes = DB::table('volumes')->where('type', 's3')->get();

        foreach ($volumes as $volume) {
            $config = json_decode($volume->config, true) ?? [];

            // Remove the per-volume AWS fields, keeping only bucket and prefix
            $config = array_intersect_key($config, array_flip(['bucket', 'prefix']));

            DB::table('volumes')
                ->where('id', $volume->id)
                ->update(['config' => json_encode($config)]);
        }
    }
};
