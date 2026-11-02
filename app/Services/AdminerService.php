<?php

namespace App\Services;

use App\Services\Adminer\AdminerSettings;
use App\Services\Adminer\CacheSessionHandler;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Vite;

class AdminerService
{
    /**
     * Serve Adminer with auto-login credentials and custom CSS.
     *
     * @param  array<string, string>|null  $credentials
     */
    public function render(?array $credentials): void
    {
        $package = 'vrana/adminer-compiled';
        $vendorAdminer = (string) InstalledVersions::getInstallPath($package);

        $GLOBALS['_adminer_settings'] = new AdminerSettings($credentials, Vite::asset('resources/css/adminer.css'));

        session_set_save_handler(new CacheSessionHandler(cache()->store(), (int) config('session.lifetime', 120) * 60));

        // Must be in the root namespace, so it lives in a separate file
        require_once __DIR__.'/adminer_object.php';

        chdir($vendorAdminer);
        include_once $vendorAdminer.'/adminer-'.InstalledVersions::getPrettyVersion($package).'.php';
    }
}
