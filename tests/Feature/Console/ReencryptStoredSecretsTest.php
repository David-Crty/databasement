<?php

use App\Enums\Concerns\HasSensitiveConfigFields;
use App\Enums\VolumeType;
use App\Models\AppConfig;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Models\Volume;
use App\Support\EncryptedColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @param  list<string>  $previousKeys
 */
function useAppKeys(string $key, array $previousKeys = []): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previousKeys]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

function newAppKey(): string
{
    return 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')));
}

test('re-encrypts stored values so they no longer need the previous key', function () {
    $oldKey = (string) config('app.key');

    $server = DatabaseServer::factory()->create(['password' => 'db-secret']);
    $volume = Volume::factory()->create([
        'type' => 's3',
        'config' => VolumeType::S3->encryptSensitiveFields(['bucket' => 'b', 'secret_access_key' => 's3-secret']),
    ]);
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('totp-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
    ]);
    AppConfig::create([
        'id' => 'test.secret',
        'value' => AppConfig::prepareValue('config-secret', true),
        'type' => 'string',
        'is_sensitive' => true,
    ]);

    $newKey = newAppKey();
    useAppKeys($newKey, [$oldKey]);

    $this->artisan('key:reencrypt')->assertExitCode(0);

    useAppKeys($newKey);

    expect($server->fresh()->password)->toBe('db-secret')
        ->and($volume->fresh()->getDecryptedConfig()['secret_access_key'])->toBe('s3-secret')
        ->and($volume->fresh()->config['bucket'])->toBe('b')
        ->and(decrypt($user->fresh()->two_factor_secret))->toBe('totp-secret')
        ->and(AppConfig::find('test.secret')->getCastedValue())->toBe('config-secret');
});

test('--check reports values that still need a previous key without changing them', function () {
    $oldKey = (string) config('app.key');
    $server = DatabaseServer::factory()->create(['password' => 'db-secret']);
    $stored = DB::table('database_servers')->where('id', $server->id)->value('password');

    useAppKeys(newAppKey(), [$oldKey]);

    $this->artisan('key:reencrypt', ['--check' => true])->assertExitCode(1);
    expect(DB::table('database_servers')->where('id', $server->id)->value('password'))->toBe($stored);

    $this->artisan('key:reencrypt')->assertExitCode(0);
    $this->artisan('key:reencrypt', ['--check' => true])->assertExitCode(0);
});

test('leaves a value no configured key can decrypt unchanged and fails', function () {
    $server = DatabaseServer::factory()->create();
    $foreign = (new Encrypter(Str::random(32), 'aes-256-cbc'))->encryptString('lost');
    DB::table('database_servers')->where('id', $server->id)->update(['password' => $foreign]);

    $this->artisan('key:reencrypt')->assertExitCode(1);

    expect(DB::table('database_servers')->where('id', $server->id)->value('password'))->toBe($foreign);
});

test('leaves plaintext config fields saved before encryption as they are', function () {
    $volume = Volume::factory()->create([
        'type' => 's3',
        'config' => ['bucket' => 'b', 'secret_access_key' => 'legacy-plaintext'],
    ]);

    $this->artisan('key:reencrypt')->assertExitCode(0);

    expect($volume->fresh()->config['secret_access_key'])->toBe('legacy-plaintext');
});

test('every encrypted model cast and sensitive config type is registered for re-encryption', function () {
    $registered = collect(EncryptedColumns::columns())
        ->flatMap(fn (array $entry) => array_map(fn (string $column) => "{$entry['model']}.{$column}", $entry['columns']));

    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        foreach ((new $class)->getCasts() as $attribute => $cast) {
            if (is_string($cast) && Str::startsWith(strtolower($cast), ['encrypted', 'illuminate\\database\\eloquent\\casts\\asencrypted'])) {
                expect($registered)->toContain("{$class}.{$attribute}");
            }
        }
    }

    $configTypes = array_column(EncryptedColumns::configColumns(), 'type');

    foreach (glob(app_path('Enums/*.php')) as $file) {
        $class = 'App\\Enums\\'.basename($file, '.php');

        if (in_array(HasSensitiveConfigFields::class, class_uses($class), true)) {
            expect($configTypes)->toContain($class);
        }
    }
});
