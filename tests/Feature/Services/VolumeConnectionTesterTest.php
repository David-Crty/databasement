<?php

use App\Services\VolumeConnectionTester;

beforeEach(function () {
    $this->tester = new VolumeConnectionTester;
});

describe('local volume connection testing', function () {
    test('testConnection returns success for valid writable directory', function () {
        // Create a temp directory for testing
        $tempDir = sys_get_temp_dir().'/volume-test-'.uniqid();
        mkdir($tempDir, 0777, true);

        try {
            $result = $this->tester->test([
                'type' => 'local',
                'path' => $tempDir,
            ]);

            expect($result['success'])->toBeTrue()
                ->and($result['message'])->toContain('Connection successful');
        } finally {
            // Cleanup
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    });

    test('testConnection creates directory if it does not exist', function () {
        $tempDir = sys_get_temp_dir().'/volume-test-new-'.uniqid();

        try {
            // Directory should not exist yet
            expect(is_dir($tempDir))->toBeFalse();

            $result = $this->tester->test([
                'type' => 'local',
                'path' => $tempDir,
            ]);

            // Directory should now exist and test should succeed
            expect($result['success'])->toBeTrue()
                ->and(is_dir($tempDir))->toBeTrue()
                ->and($result['message'])->toContain('Connection successful');
        } finally {
            // Cleanup
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    });

    test('testConnection returns error when directory cannot be created', function () {
        // Use a path that cannot be created (no permission to create in root)
        $result = $this->tester->test([
            'type' => 'local',
            'path' => '/nonexistent-root-'.uniqid().'/subdir',
        ]);

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('Failed to create directory');
    });

    test('testConnection returns error when path is empty', function () {
        $result = $this->tester->test([
            'type' => 'local',
            'path' => '',
        ]);

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('Path is required');
    });

    test('testConnection returns error for unsupported volume type', function () {
        $result = $this->tester->test([
            'type' => 'unknown',
        ]);

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('Unsupported volume type');
    });

    test('testConnection creates and removes test file during validation', function () {
        $tempDir = sys_get_temp_dir().'/volume-test-'.uniqid();
        mkdir($tempDir, 0777, true);

        try {
            // Before test, directory should be empty
            expect(glob($tempDir.'/*'))->toBeEmpty();

            $result = $this->tester->test([
                'type' => 'local',
                'path' => $tempDir,
            ]);

            // After test, directory should still be empty (test file cleaned up)
            expect(glob($tempDir.'/*'))->toBeEmpty()
                ->and($result['success'])->toBeTrue();
        } finally {
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    });
});

describe('S3 volume connection testing', function () {
    test('testConnection returns error when bucket is empty', function () {
        $result = $this->tester->test([
            'type' => 's3',
            'bucket' => '',
        ]);

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('Bucket name is required');
    });

    test('testConnection returns error when AWS credentials are not configured', function () {
        // Ensure no env credentials
        $originalKey = env('AWS_ACCESS_KEY_ID');
        $originalSecret = env('AWS_SECRET_ACCESS_KEY');

        // Clear credentials by not providing them in config
        $result = $this->tester->test([
            'type' => 's3',
            'bucket' => 'test-bucket',
            'key' => '',
            'secret' => '',
        ]);

        // This test will fail if AWS credentials are actually configured in environment
        // In that case, it will try to connect to S3, which is fine for a real test
        expect($result['success'])->toBeFalse();
    });
});
