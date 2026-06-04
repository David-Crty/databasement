<?php

namespace App\Services;

class AdminerService
{
    /**
     * Serve Adminer with auto-login credentials and custom CSS.
     *
     * @param  array<string, string>|null  $credentials
     */
    public function render(?array $credentials): void
    {
        $vendorAdminer = base_path('vendor/dg/adminer');

        $GLOBALS['_adminer_credentials'] = $credentials;
        $GLOBALS['_adminer_css_path'] = $this->resolveViteCssPath();

        $this->useNonLockingSessionHandler();
        $this->defineAdminerObject();

        chdir($vendorAdminer);
        include $vendorAdminer.'/adminer.php';
    }

    /**
     * Resolve the Vite-built adminer CSS path from the build manifest.
     */
    private function resolveViteCssPath(): string
    {
        $manifestPath = public_path('build/manifest.json');

        if (file_exists($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);

            if (isset($manifest['resources/css/adminer.css']['file'])) {
                return '/build/'.$manifest['resources/css/adminer.css']['file'];
            }
        }

        return '/css/adminer.css';
    }

    /**
     * Register a non-locking session handler backed by Laravel's cache store
     * so Adminer's session_start() doesn't block concurrent requests and
     * works across multiple pods/containers (unlike the default file handler).
     */
    private function useNonLockingSessionHandler(): void
    {
        $cache = cache()->store();
        $ttl = (int) config('session.lifetime', 120) * 60;

        session_set_save_handler(new class($cache, $ttl) implements \SessionHandlerInterface
        {
            public function __construct(
                private \Illuminate\Contracts\Cache\Repository $cache,
                private int $ttl,
            ) {}

            public function open(string $path, string $name): bool
            {
                return true;
            }

            public function close(): bool
            {
                return true;
            }

            public function read(string $id): string
            {
                $value = $this->cache->get('adminer_session:'.$id);

                return $value ? base64_decode($value) : '';
            }

            public function write(string $id, string $data): bool
            {
                return $this->cache->put('adminer_session:'.$id, base64_encode($data), $this->ttl);
            }

            public function destroy(string $id): bool
            {
                return $this->cache->forget('adminer_session:'.$id);
            }

            public function gc(int $max_lifetime): int
            {
                return 0; // Cache TTL handles expiry
            }
        });
    }

    /**
     * Define the global adminer_object() function that Adminer calls
     * to get its configuration (auto-login, iframe headers, custom CSS).
     * Must be in the root namespace — loaded from a separate file.
     */
    private function defineAdminerObject(): void
    {
        require_once __DIR__.'/adminer_object.php';
    }
}
