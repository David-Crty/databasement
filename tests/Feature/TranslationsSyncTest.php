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

test('check reports missing translations without writing and exits non-zero', function () {
    writeLangFile($this->langPath, 'en', ['Backup' => 'Backup', 'Restore' => 'Restore']);
    writeLangFile($this->langPath, 'fr', ['Stale' => 'Obsolète', 'Backup' => 'Backup']);

    $this->artisan('translations:sync --check')
        ->expectsOutputToContain('fr: 1 missing')
        ->assertFailed();

    expect(readLangFile($this->langPath, 'fr'))->toHaveKey('Stale');
});

test('check flags encoding artifacts the English source does not have', function () {
    writeLangFile($this->langPath, 'en', [
        'Update the application' => 'Update the application',
        'Backup & Restore' => 'Backup & Restore',
    ]);
    writeLangFile($this->langPath, 'fr', [
        'Update the application' => "Mettre à jour l'application",
        'Backup & Restore' => 'Backup & Restore',
    ]);

    $this->artisan('translations:sync --check')
        ->expectsOutputToContain('1 with encoding artifacts')
        ->expectsOutputToContain('ASCII apostrophe: Update the application')
        ->assertFailed();
});

test('check flags a translation that drops a placeholder or leaves a count unmatched', function () {
    writeLangFile($this->langPath, 'en', [
        '{1} :count snapshot deleted|[2,*] :count snapshots deleted' => '{1} :count snapshot deleted|[2,*] :count snapshots deleted',
        'Sent to :name' => 'Sent to :name',
    ]);
    writeLangFile($this->langPath, 'fr', [
        '{1} :count snapshot deleted|[2,*] :count snapshots deleted' => '{1} :count snapshot supprimé|[2,5] :count snapshots supprimés',
        'Sent to :name' => 'Envoyé au destinataire',
    ]);

    $this->artisan('translations:sync --check')
        ->expectsOutputToContain('2 structurally broken')
        ->expectsOutputToContain('plural ranges: no branch matches a count of')
        ->expectsOutputToContain('placeholders: expected :name, got (none)')
        ->assertFailed();
});

test('check accepts extra plural branches when they still cover the source counts', function () {
    writeLangFile($this->langPath, 'en', [
        '{1} :count snapshot deleted|[2,*] :count snapshots deleted' => '{1} :count snapshot deleted|[2,*] :count snapshots deleted',
    ]);
    // The translator mandates three branches for Chinese, spelling out 一 and 兩
    // instead of reusing the digit. Valid Laravel, and the ranges still cover 1..*.
    writeLangFile($this->langPath, 'zh_TW', [
        '{1} :count snapshot deleted|[2,*] :count snapshots deleted' => '{1} 已刪除一個 Snapshot|{2} 已刪除兩個 Snapshot|[3,*] 已刪除 :count 個 Snapshot',
    ]);
    config(['app.available_locales' => ['en' => 'English', 'zh_TW' => '繁體中文']]);

    $this->artisan('translations:sync --check')->assertSuccessful();
});

test('check reports a lang file that is not a configured locale', function () {
    writeLangFile($this->langPath, 'en', ['Backup' => 'Backup']);
    writeLangFile($this->langPath, 'fr', ['Backup' => 'Backup']);
    writeLangFile($this->langPath, 'fr,es,el', ['Backup' => 'Backup']);

    $this->artisan('translations:sync --check')
        ->expectsOutputToContain('lang/fr,es,el.json is not a configured locale')
        ->assertFailed();
});
