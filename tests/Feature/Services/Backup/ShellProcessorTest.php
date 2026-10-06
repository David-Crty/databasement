<?php

use App\Exceptions\Backup\JobRevokedException;
use App\Exceptions\ShellProcessFailed;
use App\Models\BackupJob;
use App\Services\Backup\InMemoryBackupLogger;
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

/**
 * A logger recording every report of a command, or failing them all with $failure.
 */
function commandReportSpy(?Throwable $failure = null): InMemoryBackupLogger
{
    return new class($failure) extends InMemoryBackupLogger
    {
        /** @var list<array<string, mixed>> */
        public array $reports = [];

        public function __construct(private readonly ?Throwable $failure) {}

        public function updateCommandLog(int $index, array $data): void
        {
            if ($this->failure !== null) {
                throw $this->failure;
            }

            $this->reports[] = $data;
            parent::updateCommandLog($index, $data);
        }
    };
}

describe('reporting a running command', function () {
    test('it is reported every interval with its output so far, then once finished', function () {
        $logger = commandReportSpy();
        $processor = new ShellProcessor(progressIntervalSeconds: 0.2);
        $processor->setLogger($logger);

        $processor->process('echo first; sleep 1; echo second');

        $whileRunning = collect($logger->reports)->reject(fn (array $report) => isset($report['status']));
        $final = collect($logger->reports)->last();

        expect($whileRunning)->not->toBeEmpty()
            ->and($whileRunning->first()['output'])->toBe('first')
            ->and($final['status'])->toBe('completed')
            ->and($final['output'])->toBe("first\nsecond");
    });

    test('a chatty command is reported once per interval, not once per output chunk', function () {
        $logger = commandReportSpy();
        $processor = new ShellProcessor(progressIntervalSeconds: 3600);
        $processor->setLogger($logger);

        // ~2 MB delivered as many read chunks: every report re-serializes the
        // job's whole `logs` blob, so one per chunk would be quadratic.
        $processor->process('seq 1 300000');

        expect($logger->reports)->toHaveCount(1);
    });

    test('a report that fails never stops the command', function () {
        Log::spy();
        $processor = new ShellProcessor(progressIntervalSeconds: 0.1);
        $processor->setLogger(commandReportSpy(new RuntimeException('Server unreachable')));

        expect($processor->process('sleep 0.3; echo done'))->toBe("done\n");
    });

    test('a job the server revoked stops the command', function () {
        $processor = new ShellProcessor(progressIntervalSeconds: 0.1);
        $processor->setLogger(commandReportSpy(new JobRevokedException('Job was reassigned')));
        $startedAt = microtime(true);

        expect(fn () => $processor->process('sleep 10'))->toThrow(JobRevokedException::class);
        expect(microtime(true) - $startedAt)->toBeLessThan(5);
    });

    test('a stopped command also stops the processes it started', function () {
        $marker = sys_get_temp_dir().'/shell-processor-'.uniqid();
        $processor = new ShellProcessor(progressIntervalSeconds: 0.1);
        $processor->setLogger(commandReportSpy(new JobRevokedException('Job was cancelled')));

        expect(fn () => $processor->process('sh -c '.escapeshellarg('sleep 0.5; touch '.$marker).'; true'))->toThrow(JobRevokedException::class);

        usleep(800_000);
        expect(file_exists($marker))->toBeFalse();
    });
});
