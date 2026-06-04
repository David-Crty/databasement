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
    Route::livewire('dashboard', \App\Livewire\Dashboard::class)
        ->name('dashboard');

    // Index pages - viewable by all roles
    Route::livewire('database-servers', \App\Livewire\DatabaseServer\Index::class)
        ->name('database-servers.index');
    Route::livewire('volumes', \App\Livewire\Volume\Index::class)
        ->name('volumes.index');
    Route::livewire('snapshots', \App\Livewire\Snapshot\Index::class)
        ->name('snapshots.index');
    Route::livewire('restores', \App\Livewire\Restore\Index::class)
        ->name('restores.index');
    Route::livewire('scheduled-restores', \App\Livewire\ScheduledRestore\Index::class)
        ->name('scheduled-restores.index');
    Route::redirect('jobs', 'snapshots');

    // Users index - viewable by all (actions restricted in component)
    Route::livewire('users', \App\Livewire\User\Index::class)
        ->name('users.index');

    // Configuration
    Route::get('configuration', fn () => redirect()->route('configuration.application'));
    Route::livewire('configuration/application', \App\Livewire\Configuration\Application::class)
        ->name('configuration.application');
    Route::livewire('configuration/backup', \App\Livewire\Configuration\Backup::class)
        ->name('configuration.backup');
    Route::livewire('configuration/notification', \App\Livewire\Configuration\Notification::class)
        ->name('configuration.notification');
    Route::livewire('configuration/authentication', \App\Livewire\Configuration\Authentication::class)
        ->name('configuration.authentication');
    Route::livewire('configuration/organizations', \App\Livewire\Configuration\Organization::class)
        ->name('configuration.organizations');

    // Agents
    Route::livewire('agents', \App\Livewire\Agent\Index::class)
        ->name('agents.index');

    // API Tokens
    Route::livewire('tokens', \App\Livewire\ApiToken\Index::class)
        ->name('api-tokens.index');
});

// Snapshot download - dedicated route to avoid Livewire OOM on large files
Route::middleware(['auth'])->group(function () {
    Route::get('snapshots/{snapshot}/download', \App\Http\Controllers\Web\SnapshotDownloadController::class)
        ->name('snapshots.download');
});

// Adminer database browser (excluded from CSRF in bootstrap/app.php)
Route::any('/adminer', \App\Http\Controllers\Web\AdminerController::class)
    ->middleware('auth')
    ->name('adminer');

// Action routes - authorization handled by Policies in components
Route::middleware(['auth'])->group(function () {
    // Database Servers
    Route::livewire('database-servers/create', \App\Livewire\DatabaseServer\Create::class)
        ->name('database-servers.create');
    Route::livewire('database-servers/{server}', \App\Livewire\DatabaseServer\Show::class)
        ->name('database-servers.show');
    Route::livewire('database-servers/{server}/edit', \App\Livewire\DatabaseServer\Edit::class)
        ->name('database-servers.edit');

    // Volumes
    Route::livewire('volumes/create', \App\Livewire\Volume\Create::class)
        ->name('volumes.create');
    Route::livewire('volumes/{volume}/edit', \App\Livewire\Volume\Edit::class)
        ->name('volumes.edit');

    // Agents
    Route::livewire('agents/create', \App\Livewire\Agent\Create::class)
        ->name('agents.create');
    Route::livewire('agents/{agent}/edit', \App\Livewire\Agent\Edit::class)
        ->name('agents.edit');

    // User management
    Route::livewire('users/create', \App\Livewire\User\Create::class)
        ->name('users.create');
    Route::livewire('users/{user}/edit', \App\Livewire\User\Edit::class)
        ->name('users.edit');
});

// Settings - all authenticated users
Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', \App\Livewire\Settings\Profile::class)
        ->name('profile.edit');
    Route::livewire('settings/password', \App\Livewire\Settings\Password::class)
        ->name('user-password.edit');
    Route::livewire('settings/preferences', \App\Livewire\Settings\Preferences::class)
        ->name('preferences.edit');

    Route::livewire('settings/two-factor', \App\Livewire\Settings\TwoFactor::class)
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
