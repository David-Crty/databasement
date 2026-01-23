<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

// Health check routes (public)
Route::get('/health', [\App\Http\Controllers\Web\HealthCheckController::class, 'up'])
    ->name('health.up');
Route::get('/health/debug', [\App\Http\Controllers\Web\HealthCheckController::class, 'debug'])
    ->name('health.debug');

// Home - redirect based on auth status and user count
Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return User::count() === 0
        ? redirect()->route('register')
        : redirect()->route('login');
})->name('home');

// Invitation routes (public)
Route::get('/invitation/{token}', \App\Livewire\Auth\AcceptInvitation::class)
    ->name('invitation.accept');

// OAuth routes (public - handle their own auth state)
Route::prefix('oauth')->name('oauth.')->group(function () {
    Route::get('{provider}/redirect', [\App\Http\Controllers\Web\OAuthController::class, 'redirect'])
        ->name('redirect');
    Route::get('{provider}/callback', [\App\Http\Controllers\Web\OAuthController::class, 'callback'])
        ->name('callback');
});

// Main resources - all authenticated users can view index pages
Route::middleware(['auth'])->group(function () {
    // Dashboard
    Route::get('dashboard', \App\Livewire\Dashboard::class)
        ->name('dashboard');

    // Index pages - viewable by all roles
    Route::get('database-servers', \App\Livewire\DatabaseServer\Index::class)
        ->name('database-servers.index');
    Route::get('volumes', \App\Livewire\Volume\Index::class)
        ->name('volumes.index');
    Route::get('jobs', \App\Livewire\BackupJob\Index::class)
        ->name('jobs.index');

    // Users index - viewable by all (actions restricted in component)
    Route::get('users', \App\Livewire\User\Index::class)
        ->name('users.index');

    // Configuration - read-only view of app configuration
    Route::get('configuration', \App\Livewire\Configuration\Index::class)
        ->name('configuration.index');

    // API Tokens
    Route::get('api-tokens', \App\Livewire\ApiToken\Index::class)
        ->name('api-tokens.index');
});

// Action routes - authorization handled by Policies in components
Route::middleware(['auth'])->group(function () {
    // Database Servers
    Route::get('database-servers/create', \App\Livewire\DatabaseServer\Create::class)
        ->name('database-servers.create');
    Route::get('database-servers/{server}/edit', \App\Livewire\DatabaseServer\Edit::class)
        ->name('database-servers.edit');

    // Volumes
    Route::get('volumes/create', \App\Livewire\Volume\Create::class)
        ->name('volumes.create');
    Route::get('volumes/{volume}/edit', \App\Livewire\Volume\Edit::class)
        ->name('volumes.edit');

    // User management
    Route::get('users/create', \App\Livewire\User\Create::class)
        ->name('users.create');
    Route::get('users/{user}/edit', \App\Livewire\User\Edit::class)
        ->name('users.edit');
});

// Settings - all authenticated users
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
