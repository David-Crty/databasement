<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->langPath = sys_get_temp_dir().'/lang-'.Str::random(8);
    File::makeDirectory($this->langPath);
    app()->useLangPath($this->langPath);
    config(['app.available_locales' => ['en' => 'English', 'fr' => 'Français']]);
});

afterEach(function () {
    File::deleteDirectory($this->langPath);
});

function writeLangFile(string $path, string $locale, array $content): void
{
    File::put("{$path}/{$locale}.json", json_encode($content, JSON_UNESCAPED_UNICODE));
}

function readLangFile(string $path, string $locale): array
{
    return json_decode(File::get("{$path}/{$locale}.json"), true);
}

test('sync prunes stale keys, follows the source order and never invents translations', function () {
    writeLangFile($this->langPath, 'en', [
        'Backup' => 'Backup',
        'Restore' => 'Restore',
        'Snapshot' => 'Snapshot',
    ]);
    writeLangFile($this->langPath, 'fr', [
        '_comment' => 'WARNING: This is an auto-generated file.',
        'Snapshot' => 'Snapshot',
        'Removed from the app' => 'Retiré de l’application',
        'Backup' => 'Backup',
    ]);

    $this->artisan('translations:sync')->assertSuccessful();

    expect(readLangFile($this->langPath, 'fr'))->toBe([
        'Backup' => 'Backup',
        'Snapshot' => 'Snapshot',
    ]);
});

test('check reports a locale that is out of sync without writing, and exits non-zero', function () {
    writeLangFile($this->langPath, 'en', ['Backup' => 'Backup', 'Restore' => 'Restore']);
    writeLangFile($this->langPath, 'fr', ['Stale' => 'Obsolète', 'Backup' => 'Backup']);

    $this->artisan('translations:sync --check')
        ->expectsOutputToContain('fr: 1 missing, 1 stale')
        ->assertFailed();

    expect(readLangFile($this->langPath, 'fr'))->toHaveKey('Stale');
});

test('check fails on a file sync would rewrite even when no key is missing', function () {
    writeLangFile($this->langPath, 'en', ['Backup' => 'Backup', 'Restore' => 'Restore']);
    // Same keys as the source, in the wrong order and carrying the translator's banner.
    writeLangFile($this->langPath, 'fr', [
        '_comment' => 'WARNING: This is an auto-generated file.',
        'Restore' => 'Restaurer',
        'Backup' => 'Backup',
    ]);

    $this->artisan('translations:sync --check')
        ->expectsOutputToContain('fr: 0 missing, needs formatting')
        ->assertFailed();

    expect(readLangFile($this->langPath, 'fr'))->toHaveKey('_comment');
});
