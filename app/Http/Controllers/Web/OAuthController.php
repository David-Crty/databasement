<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\OAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class OAuthController extends Controller
{
    public function __construct(
        private readonly OAuthService $oAuthService
    ) {}

    /**
     * Redirect to OAuth provider.
     */
    public function redirect(string $provider): SymfonyRedirectResponse
    {
        $this->validateProvider($provider);

        $driver = $this->getDriver($provider);

        // For OIDC, we need to set scopes
        if ($provider === 'oidc' && method_exists($driver, 'scopes')) {
            $driver->scopes(['openid', 'profile', 'email']);
        }

        return $driver->redirect();
    }

    /**
     * Handle OAuth provider callback.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        try {
            $socialiteUser = $this->getDriver($provider)->user();

            if (! $socialiteUser->getEmail()) {
                return redirect()->route('login')
                    ->with('error', __('Email is required for OAuth login.'));
            }

            $user = $this->oAuthService->findOrCreateUser($socialiteUser, $provider);

            Auth::login($user, remember: config('oauth.remember_me', true));

            $request->session()->regenerate();

            return redirect()->intended(config('fortify.home', '/dashboard'));

        } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
            Log::warning('OAuth invalid state', ['provider' => $provider, 'error' => $e->getMessage()]);

            return redirect()->route('login')
                ->with('error', __('Authentication session expired. Please try again.'));

        } catch (\RuntimeException $e) {
            Log::warning('OAuth error', ['provider' => $provider, 'error' => $e->getMessage()]);

            return redirect()->route('login')
                ->with('error', $e->getMessage());

        } catch (\Exception $e) {
            Log::error('OAuth unexpected error', ['provider' => $provider, 'error' => $e->getMessage()]);

            return redirect()->route('login')
                ->with('error', __('An error occurred during authentication.'));
        }
    }

    /**
     * Validate that the provider is enabled.
     */
    private function validateProvider(string $provider): void
    {
        $providers = config('oauth.providers', []);

        if (! isset($providers[$provider]) || ! ($providers[$provider]['enabled'] ?? false)) {
            abort(404, 'OAuth provider not found or not enabled.');
        }
    }

    /**
     * Get the Socialite driver for a provider.
     */
    private function getDriver(string $provider): \Laravel\Socialite\Contracts\Provider
    {
        // Map internal provider names to Socialite driver names
        $driverMap = [
            'oidc' => 'oidc',
        ];

        $driver = $driverMap[$provider] ?? $provider;

        return Socialite::driver($driver);
    }
}
