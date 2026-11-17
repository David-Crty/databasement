<?php

namespace App\Support;

use App\Enums\NotificationChannelType;
use App\Enums\VolumeType;
use App\Models\AgentJob;
use App\Models\AppConfig;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Models\NotificationChannel;
use App\Models\OAuthIdentity;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Database\Eloquent\Model;

/**
 * Every place a value encrypted with APP_KEY is stored, for re-encrypting
 * them after a key rotation (`key:reencrypt`).
 */
class EncryptedColumns
{
    /**
     * Columns whose whole value is one encrypted payload.
     *
     * @return list<array{model: class-string<Model>, columns: list<string>, where?: array<string, mixed>}>
     */
    public static function columns(): array
    {
        return [
            ['model' => DatabaseServer::class, 'columns' => ['password']],
            ['model' => DatabaseServerSshConfig::class, 'columns' => ['password', 'private_key', 'key_passphrase']],
            ['model' => OAuthIdentity::class, 'columns' => ['access_token', 'refresh_token']],
            ['model' => AgentJob::class, 'columns' => ['payload']],
            ['model' => User::class, 'columns' => ['two_factor_secret', 'two_factor_recovery_codes']],
            ['model' => AppConfig::class, 'columns' => ['value'], 'where' => ['is_sensitive' => true]],
        ];
    }

    /**
     * JSON `config` columns in which only the sensitive fields of the row's `type` are encrypted.
     *
     * @return list<array{model: class-string<Model>, type: class-string<VolumeType>|class-string<NotificationChannelType>}>
     */
    public static function configColumns(): array
    {
        return [
            ['model' => Volume::class, 'type' => VolumeType::class],
            ['model' => NotificationChannel::class, 'type' => NotificationChannelType::class],
        ];
    }
}
