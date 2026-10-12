<?php

use App\Livewire\Settings\TwoFactor;
use App\Models\OAuthIdentity;
use App\Models\User;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    if (! Features::canManageTwoFactorAuthentication()) {
        $this->markTestSkipped('Two-factor authentication is not enabled.');
    }

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
});

test('two factor settings page can be rendered', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('two-factor.show'))
        ->assertOk()
        ->assertSee('Two Factor Authentication')
        ->assertSee('Disabled');
});

test('oauth user is blocked from two factor settings', function () {
    $user = User::factory()->create(['password' => null]);
    OAuthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_user_id' => 'gh-123',
        'email' => $user->email,
    ]);

    // First, they get redirected to password confirmation
    $this->actingAs($user)
        ->get(route('two-factor.show'))
        ->assertRedirect(route('password.confirm'));

    // Then, password confirmation blocks them with 403
    $this->actingAs($user)
        ->get(route('password.confirm'))
        ->assertForbidden();
});

test('two factor settings page requires password confirmation when enabled', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('two-factor.show'));

    $response->assertRedirect(route('password.confirm'));
});

test('two factor settings page returns forbidden response when two factor is disabled', function () {
    config(['fortify.features' => []]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('two-factor.show'));

    $response->assertForbidden();
});

test('two factor authentication disabled when confirmation abandoned between requests', function () {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($user);

    $component = Livewire::test(TwoFactor::class);

    $component->assertSet('twoFactorEnabled', false);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
    ]);
});

test('two factor authentication is enabled once the authenticator code is confirmed', function () {
    $user = User::factory()->withoutTwoFactor()->create();
    $this->actingAs($user);

    $component = Livewire::test(TwoFactor::class)
        ->call('enable')
        ->assertSet('showModal', true)
        ->assertSet('twoFactorEnabled', false)
        ->assertNotSet('qrCodeSvg', '')
        ->assertSet('manualSetupKey', decrypt($user->fresh()->two_factor_secret))
        ->call('showVerificationIfNecessary')
        ->assertSet('showVerificationStep', true)
        ->assertSee('Verify Authentication Code');

    $otp = app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));

    $component->set('code', $otp)
        ->call('confirmTwoFactor')
        ->assertHasNoErrors()
        ->assertSet('twoFactorEnabled', true)
        ->assertSet('showModal', false);

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

test('an invalid authenticator code does not confirm two factor authentication', function () {
    $user = User::factory()->withoutTwoFactor()->create();
    $this->actingAs($user);

    Livewire::test(TwoFactor::class)
        ->call('enable')
        ->call('showVerificationIfNecessary')
        ->set('code', '000000')
        ->call('confirmTwoFactor')
        ->assertHasErrors('code')
        ->assertSet('twoFactorEnabled', false)
        ->call('resetVerification')
        ->assertHasNoErrors()
        ->assertSet('code', '')
        ->assertSet('showVerificationStep', false);

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('two factor authentication is enabled immediately when confirmation is not required', function () {
    Features::twoFactorAuthentication(['confirm' => false, 'confirmPassword' => true]);
    $user = User::factory()->withoutTwoFactor()->create();
    $this->actingAs($user);

    Livewire::test(TwoFactor::class)
        ->call('enable')
        ->assertSet('twoFactorEnabled', true)
        ->assertSee('Two-Factor Authentication Enabled')
        ->call('showVerificationIfNecessary')
        ->assertSet('showVerificationStep', false)
        ->assertSet('showModal', false)
        ->assertSet('twoFactorEnabled', true);
});

test('setup data error is shown when the stored secret cannot be decrypted', function () {
    Features::twoFactorAuthentication(['confirm' => false, 'confirmPassword' => true]);
    $user = User::factory()->create(['two_factor_secret' => 'not-encrypted']);
    $this->actingAs($user);

    Livewire::test(TwoFactor::class)
        ->call('enable')
        ->assertHasErrors('setupData')
        ->assertSet('qrCodeSvg', '')
        ->assertSet('manualSetupKey', '');
});

test('two factor authentication can be disabled', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $this->actingAs($user);

    Livewire::test(TwoFactor::class)
        ->assertSet('twoFactorEnabled', true)
        ->call('disable')
        ->assertSet('twoFactorEnabled', false);

    expect($user->fresh()->two_factor_secret)->toBeNull();
});
