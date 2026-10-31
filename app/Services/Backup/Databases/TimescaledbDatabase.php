<?php

namespace App\Services\Backup\Databases;

use App\Contracts\BackupLogger;
use App\Exceptions\Backup\ConnectionException;
use App\Services\Backup\DTO\DatabaseOperationResult;

/**
 * TimescaleDB is a PostgreSQL extension, so backups are taken with the same
 * libpq tooling (pg_dump/pg_restore/psql) and everything in
 * {@see PostgresqlDatabase} applies unchanged except the restore sequence.
 *
 * A dump of a database with hypertables cannot simply be replayed: TimescaleDB
 * installs event triggers and rewrites inserts into chunk tables, which fight a
 * plain restore. The extension provides two functions to bracket the restore,
 * `timescaledb_pre_restore()` and `timescaledb_post_restore()`, which toggle the
 * database-level `timescaledb.restoring` GUC. Both have to run on the target
 * database, and the extension has to exist there before `pre_restore` is called.
 */
class TimescaledbDatabase extends PostgresqlDatabase
{
    /**
     * Recreate the target database as PostgreSQL would, then make sure the
     * TimescaleDB extension is present so {@see restore()} can call
     * `timescaledb_pre_restore()`. The dump also carries `CREATE EXTENSION`, but
     * that runs too late: `pre_restore` precedes the dump and needs it already
     * loaded.
     */
    public function prepareForRestore(string $schemaName, BackupLogger $logger, bool $forceDatabase = false): void
    {
        parent::prepareForRestore($schemaName, $logger, $forceDatabase);

        $createExtension = 'CREATE EXTENSION IF NOT EXISTS timescaledb';

        try {
            $logger->logCommand($createExtension, null, 0);
            $this->createPdoForDatabase($schemaName)->exec($createExtension);
        } catch (\PDOException $e) {
            throw new ConnectionException("Failed to prepare TimescaleDB extension: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Wrap PostgreSQL's restore command with the pre/post restore calls. They
     * run in separate psql sessions, which is fine: the functions persist the
     * restoring flag on the database with ALTER DATABASE, so the restore session
     * in between picks it up. Chaining with `&&` keeps `post_restore` from
     * running (and re-enabling triggers on a half-loaded database) when the
     * restore itself fails.
     */
    public function restore(string $inputPath): DatabaseOperationResult
    {
        $base = parent::restore($inputPath);

        $command = implode(' && ', [
            $this->getQueryCommand('SELECT public.timescaledb_pre_restore();'),
            (string) $base->command,
            $this->getQueryCommand('SELECT public.timescaledb_post_restore();'),
        ]);

        return new DatabaseOperationResult(
            command: $command,
            log: $base->log,
            writesToStdout: $base->writesToStdout,
        );
    }
}
