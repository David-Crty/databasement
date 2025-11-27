<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('database-servers', \App\Livewire\DatabaseServer\Index::class)
        ->name('database-servers.index');
    Route::get('database-servers/create', \App\Livewire\DatabaseServer\Create::class)
        ->name('database-servers.create');
    Route::get('database-servers/{server}/edit', \App\Livewire\DatabaseServer\Edit::class)
        ->name('database-servers.edit');

    Route::get('volumes', \App\Livewire\Volume\Index::class)
        ->name('volumes.index');
    Route::get('volumes/create', \App\Livewire\Volume\Create::class)
        ->name('volumes.create');
    Route::get('volumes/{volume}/edit', \App\Livewire\Volume\Edit::class)
        ->name('volumes.edit');

    Route::get('snapshots', \App\Livewire\Snapshot\Index::class)
        ->name('snapshots.index');
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', \App\Livewire\Settings\Profile::class)
        ->name('profile.edit');
    Route::get('settings/password', \App\Livewire\Settings\Password::class)
        ->name('user-password.edit');
    Route::get('settings/appearance', \App\Livewire\Settings\Appearance::class)
        ->name('appearance.edit');

    Route::get('settings/two-factor', \App\Livewire\Settings\TwoFactor::class)
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                    && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('two-factor.show');
});

Volt::route('/users', 'users.index');
