<?php

namespace App\Console\Commands;

use App\Support\EncryptedColumns;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ReencryptStoredSecrets extends Command
{
    protected $signature = 'key:reencrypt
        {--check : Only report the values that still need a previous key, without changing them}';

    protected $description = 'Re-encrypt every stored credential with the current APP_KEY after a key rotation';

    private Encrypter $currentKeyOnly;

    public function handle(): int
    {
        $this->currentKeyOnly = new Encrypter(Crypt::getKey(), (string) config('app.cipher'));
        $check = (bool) $this->option('check');

        $rows = [];

        foreach (EncryptedColumns::columns() as $entry) {
            $model = new $entry['model'];

            foreach ($entry['columns'] as $column) {
                $rows[] = [
                    "{$model->getTable()}.{$column}",
                    ...$this->processColumn($model, $column, $entry['where'] ?? [], $check),
                ];
            }
        }

        foreach (EncryptedColumns::configColumns() as $entry) {
            $model = new $entry['model'];

            $rows[] = [
                "{$model->getTable()}.config",
                ...$this->processConfigColumn($model, $entry['type'], $check),
            ];
        }

        $this->table(['Column', 'Current key', $check ? 'Needs a previous key' : 'Re-encrypted', 'Unreadable'], $rows);

        $stale = array_sum(array_column($rows, 2));
        $unreadable = array_sum(array_column($rows, 3));

        if ($unreadable > 0) {
            $this->error("{$unreadable} value(s) cannot be decrypted with APP_KEY or APP_PREVIOUS_KEYS and were left unchanged. Add the key they were encrypted with to APP_PREVIOUS_KEYS, or enter those credentials again.");

            return self::FAILURE;
        }

        if ($check && $stale > 0) {
            $this->error("{$stale} value(s) still need a previous key. Run `php artisan key:reencrypt` before removing APP_PREVIOUS_KEYS.");

            return self::FAILURE;
        }

        $this->info($check
            ? 'Every stored value is encrypted with the current APP_KEY. APP_PREVIOUS_KEYS can be removed.'
            : "Re-encrypted {$stale} value(s) with the current APP_KEY.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $where
     * @return array{int, int, int} current, stale, unreadable
     */
    private function processColumn(Model $model, string $column, array $where, bool $check): array
    {
        $counts = [0, 0, 0];
        $table = $model->getTable();
        $keyName = $model->getKeyName();

        DB::transaction(function () use (&$counts, $table, $keyName, $column, $where, $check): void {
            $rows = DB::table($table)
                ->where($where)
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->lazyById(200, $keyName);

            foreach ($rows as $row) {
                [$state, $reencrypted] = $this->reencrypt($row->{$column});
                $counts[$state]++;

                if ($reencrypted !== null && ! $check) {
                    DB::table($table)->where($keyName, $row->{$keyName})->update([$column => $reencrypted]);
                }
            }
        });

        return $counts;
    }

    /**
     * @param  class-string<\App\Enums\VolumeType>|class-string<\App\Enums\NotificationChannelType>  $typeEnum
     * @return array{int, int, int} current, stale, unreadable
     */
    private function processConfigColumn(Model $model, string $typeEnum, bool $check): array
    {
        $counts = [0, 0, 0];
        $table = $model->getTable();
        $keyName = $model->getKeyName();

        DB::transaction(function () use (&$counts, $table, $keyName, $typeEnum, $check): void {
            foreach (DB::table($table)->whereNotNull('config')->lazyById(200, $keyName) as $row) {
                $config = json_decode($row->config, true);
                $type = $typeEnum::tryFrom($row->type);

                if (! is_array($config) || $type === null) {
                    continue;
                }

                $changed = false;

                foreach ($type->sensitiveFields() as $field) {
                    $value = $config[$field] ?? null;

                    if (! is_string($value) || ! $this->isEncryptedPayload($value)) {
                        continue;
                    }

                    [$state, $reencrypted] = $this->reencrypt($value);
                    $counts[$state]++;

                    if ($reencrypted !== null) {
                        $config[$field] = $reencrypted;
                        $changed = true;
                    }
                }

                if ($changed && ! $check) {
                    DB::table($table)->where($keyName, $row->{$keyName})->update(['config' => json_encode($config)]);
                }
            }
        });

        return $counts;
    }

    /**
     * Decrypts without unserializing, so the stored bytes come back unchanged
     * whatever they hold (a string, a serialized value or JSON).
     *
     * @return array{0: 0|1|2, 1: ?string} the state (0 current, 1 stale, 2 unreadable) and the new payload when stale
     */
    private function reencrypt(string $payload): array
    {
        try {
            $this->currentKeyOnly->decryptString($payload);

            return [0, null];
        } catch (DecryptException) {
        }

        try {
            return [1, Crypt::encryptString(Crypt::decryptString($payload))];
        } catch (DecryptException) {
            return [2, null];
        }
    }

    /**
     * Config fields saved before they were encrypted still hold plaintext,
     * which the app reads as-is and no key applies to.
     */
    private function isEncryptedPayload(string $value): bool
    {
        $decoded = json_decode((string) base64_decode($value, true), true);

        return is_array($decoded) && isset($decoded['iv'], $decoded['value'], $decoded['mac']);
    }
}
