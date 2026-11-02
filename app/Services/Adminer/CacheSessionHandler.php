<?php

namespace App\Services\Adminer;

use Illuminate\Contracts\Cache\Repository;
use SessionHandlerInterface;

/**
 * Non-locking session storage for Adminer, so its session_start() doesn't block
 * concurrent requests and works across multiple pods/containers (unlike the
 * default file handler).
 */
class CacheSessionHandler implements SessionHandlerInterface
{
    public function __construct(
        private Repository $cache,
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
        $value = $this->cache->get($this->key($id));

        return $value ? base64_decode($value) : '';
    }

    public function write(string $id, string $data): bool
    {
        return $this->cache->put($this->key($id), base64_encode($data), $this->ttl);
    }

    public function destroy(string $id): bool
    {
        return $this->cache->forget($this->key($id));
    }

    public function gc(int $max_lifetime): int
    {
        return 0; // Cache TTL handles expiry
    }

    private function key(string $id): string
    {
        return 'adminer_session:'.$id;
    }
}
