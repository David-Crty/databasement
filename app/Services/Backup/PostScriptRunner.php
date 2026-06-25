<?php

namespace App\Services\Backup;

use App\Contracts\BackupLogger;

class PostScriptRunner
{
    private const string SHEBANG = "#!/bin/sh\n";

    /**
     * Write the user script to a temporary `.sh` file and execute it.
     *
     * The script is prefixed with a `#!/bin/sh` shebang and runs with the given
     * environment variables. A non-zero exit code is logged as a warning but
     * never aborts the surrounding backup/restore, which already succeeded.
     *
     * The same {@see ShellProcessor} instance that runs the backup/restore must
     * be passed so the script's output is captured into the job log.
     *
     * @param  array<string, string>  $env
     */
    public function run(
        ShellProcessor $shellProcessor,
        BackupLogger $logger,
        string $name,
        ?string $script,
        string $workingDirectory,
        array $env,
    ): void {
        $script = trim((string) $script);

        if ($script === '') {
            return;
        }

        $label = str_replace('-', ' ', $name);
        $capitalizedLabel = ucfirst($label);
        $scriptPath = rtrim($workingDirectory, '/').'/'.$name.'.sh';
        file_put_contents($scriptPath, self::SHEBANG.$script."\n");

        try {
            $logger->log("Running {$label}", 'info');
            $shellProcessor->process('sh '.escapeshellarg($scriptPath), $env);
            $logger->log("{$capitalizedLabel} completed successfully", 'success');
        } catch (\Throwable) {
            $logger->log("{$capitalizedLabel} failed with a non-zero exit code", 'warning');
        }
    }
}
