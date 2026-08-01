<?php

use App\Exceptions\ShellProcessFailed;
use App\Models\BackupJob;
use App\Services\Backup\ShellProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('process returns command output', function () {
    $processor = new ShellProcessor;

    $output = $processor->process('echo "hello world"');

    expect(trim($output))->toBe('hello world');
});

test('process throws exception on failed command', function () {
    // Silence expected error log output
    Log::spy();

    $processor = new ShellProcessor;

    $processor->process('exit 1');
})->throws(ShellProcessFailed::class);

test('process logs command execution lifecycle', function () {
    $backupJob = BackupJob::create([
        'status' => 'running',
    ]);

    $processor = new ShellProcessor;
    $processor->setLogger($backupJob);

    $processor->process('echo "test output"');

    $backupJob->refresh();
    $logs = $backupJob->getLogs();

    expect($logs)->toHaveCount(1);

    $commandLog = $logs[0];
    expect($commandLog['type'])->toBe('command')
        ->and($commandLog['command'])->toBe('echo "test output"')
        ->and($commandLog['status'])->toBe('completed')
        ->and($commandLog['exit_code'])->toBe(0)
        ->and($commandLog['output'])->toContain('test output')
        ->and($commandLog['duration_ms'])->toBeGreaterThan(0);
});

test('process logs failed command with error status', function () {
    // Silence expected error log output
    Log::spy();

    $backupJob = BackupJob::create([
        'status' => 'running',
    ]);

    $processor = new ShellProcessor;
    $processor->setLogger($backupJob);

    try {
        $processor->process('echo "error" >&2 && exit 1');
    } catch (ShellProcessFailed) {
        // Expected exception
    }

    $backupJob->refresh();
    $logs = $backupJob->getLogs();

    expect($logs)->toHaveCount(1);

    $commandLog = $logs[0];
    expect($commandLog['status'])->toBe('failed')
        ->and($commandLog['exit_code'])->toBe(1)
        ->and($commandLog['output'])->toContain('error');
});

test('process sanitizes mysql password in logs', function () {
    $backupJob = BackupJob::create([
        'status' => 'running',
    ]);

    $processor = new ShellProcessor;
    $processor->setLogger($backupJob);

    // Use short form -p to test password sanitization
    $processor->process('echo -psecret123');

    $backupJob->refresh();
    $logs = $backupJob->getLogs();

    expect($logs[0]['command'])->toContain('-p***')
        ->and($logs[0]['command'])->not->toContain('secret123');
});

test('process sanitizes postgres password in logs', function () {
    $backupJob = BackupJob::create([
        'status' => 'running',
    ]);

    $processor = new ShellProcessor;
    $processor->setLogger($backupJob);

    $processor->process('echo PGPASSWORD=secret123');

    $backupJob->refresh();
    $logs = $backupJob->getLogs();

    expect($logs[0]['command'])->toContain('PGPASSWORD=***')
        ->and($logs[0]['command'])->not->toContain('secret123');
});

test('process works without logger', function () {
    $processor = new ShellProcessor;

    $output = $processor->process('echo "no logger"');

    expect(trim($output))->toBe('no logger');
});

test('process bounds the stored output of a chatty command', function () {
    $backupJob = BackupJob::create([
        'status' => 'running',
    ]);

    // 64-byte head/tail budget, so a few hundred bytes of warnings overflow it.
    $processor = new ShellProcessor(outputHeadBytes: 64, outputTailBytes: 64);
    $processor->setLogger($backupJob);

    // A noisy pg_dump repeating a warning on stderr, in miniature.
    $processor->process('seq 1 100 | sed "s/^/warning: line /" >&2');

    $backupJob->refresh();
    $output = $backupJob->getLogs()[0]['output'];

    expect(strlen($output))->toBeLessThan(300)
        ->and($output)->toContain('warning: line 1')
        ->and($output)->toContain('warning: line 100')
        ->and($output)->not->toContain('warning: line 50')
        ->and($output)->toContain('of output omitted');
});

test('process bounds the error message thrown for a failing chatty command', function () {
    Log::spy();

    $processor = new ShellProcessor(outputHeadBytes: 64, outputTailBytes: 64);

    $run = fn () => $processor->process('seq 1 100 | sed "s/^/warning: line /" >&2; exit 1');

    expect($run)->toThrow(
        fn (ShellProcessFailed $e) => expect(strlen($e->getMessage()))->toBeLessThan(300)
    );
});

test('process throttles incremental log writes while a command runs', function () {
    $logger = new class implements \App\Contracts\BackupLogger
    {
        public int $updates = 0;

        public function logCommand(string $command, ?string $output = null, ?int $exitCode = null, ?float $startTime = null): void {}

        public function startCommandLog(string $command): int
        {
            return 0;
        }

        public function updateCommandLog(int $index, array $data): void
        {
            $this->updates++;
        }

        public function log(string $message, string $level = 'info', ?array $context = null): void {}

        public function getLogs(): array
        {
            return [];
        }
    };

    // An interval no test run can reach, so every write after the first is skipped.
    $processor = new ShellProcessor(flushIntervalSeconds: 3600);
    $processor->setLogger($logger);

    // ~2 MB delivered as many read chunks. Unthrottled this is one database
    // write per chunk, each re-serializing the whole growing `logs` blob.
    $processor->process('seq 1 300000');

    // The first chunk, then the final write once the command exits.
    expect($logger->updates)->toBe(2);
});

test('process creates log entry before command starts', function () {
    $backupJob = BackupJob::create([
        'status' => 'running',
    ]);

    $processor = new ShellProcessor;
    $processor->setLogger($backupJob);

    // Run a command that takes a moment
    $processor->process('sleep 0.1 && echo "done"');

    $backupJob->refresh();
    $logs = $backupJob->getLogs();

    // The log should exist and have a timestamp
    expect($logs)->toHaveCount(1)
        ->and($logs[0]['timestamp'])->not->toBeNull();
});
