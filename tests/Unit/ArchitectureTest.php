<?php

use Illuminate\Filesystem\Filesystem;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\Finder\SplFileInfo;

arch('actions are named after their outcome')
    ->expect('App\Actions')
    ->classes()
    ->toHaveSuffix('Action')
    ->ignoring('App\Actions\Fortify');

arch('actions expose handle()')
    ->expect('App\Actions')
    ->classes()
    ->toHaveMethod('handle')
    ->ignoring('App\Actions\Fortify');

arch('actions are objects that can be faked')
    ->expect('App\Actions')
    ->classes()
    ->toUseTraits([AsObject::class, AsFake::class])
    ->ignoring('App\Actions\Fortify');

arch('actions never become controllers, jobs, commands or listeners')
    ->expect('App\Actions')
    ->classes()
    ->not->toUseTrait(AsAction::class)
    ->ignoring('App\Actions\Fortify');

arch('actions are not final, so they can be mocked')
    ->expect('App\Actions')
    ->classes()
    ->not->toBeFinal()
    ->ignoring('App\Actions\Fortify');

arch('actions do not depend on an edge or on request context')
    ->expect('App\Actions')
    ->not->toUse([
        'App\Livewire',
        'App\Http',
        'App\Mcp',
        'App\Console',
        'Livewire',
        'Laravel\Mcp',
        'Illuminate\Http\Request',
        'Illuminate\Support\Facades\Auth',
        'Illuminate\Support\Facades\Request',
        'Illuminate\Support\Facades\Session',
        'App\Services\CurrentOrganization',
        'auth',
        'request',
        'session',
    ])
    ->ignoring('App\Actions\Fortify');

// Not an arch() rule: resolving App\Services' dependencies loads the Azure SDK,
// whose PHP 8.4 deprecations would be reported on every run.
test('only edges depend on Livewire', function () {
    $offenders = collect(['Actions', 'Services', 'Jobs', 'Mcp', 'Console', 'Models', 'Http'])
        ->flatMap(fn (string $directory) => (new Filesystem)->allFiles(dirname(__DIR__, 2)."/app/{$directory}"))
        ->filter(fn (SplFileInfo $file) => preg_match('/\\\\?App\\\\Livewire\\\\/', $file->getContents()) === 1)
        ->map(fn (SplFileInfo $file) => $file->getRelativePathname())
        ->values();

    expect($offenders)->toBeEmpty();
});
