<?php

namespace App\Services;

use Illuminate\Support\Str;
use phpseclib3\Crypt\EC;

class SshKeyGenerator
{
    /**
     * Generate a fresh Ed25519 keypair in OpenSSH format.
     *
     * @return array{private: string, public: string}
     */
    public function generate(string $comment): array
    {
        $key = EC::createKey('Ed25519');

        $sanitized = $this->sanitizeComment($comment);

        return [
            'private' => $key->toString('OpenSSH', ['comment' => $sanitized]),
            'public' => $key->getPublicKey()->toString('OpenSSH', ['comment' => $sanitized]),
        ];
    }

    /**
     * Build the comment embedded in the public key line. Falls back to the host
     * when the name is empty, and to a random token if both are blank.
     */
    public function buildComment(string $name, string $host): string
    {
        $source = trim($name) !== '' ? $name : trim($host);

        if ($source === '') {
            return 'databasement:'.Str::lower(Str::random(8));
        }

        // Replace dots with hyphens first so hostnames like bastion.example.com
        // stay readable as bastion-example-com instead of bastionexamplecom.
        $slug = Str::slug(str_replace('.', '-', $source));

        if ($slug === '') {
            $slug = Str::lower(Str::random(8));
        }

        return 'databasement:'.$slug;
    }

    /**
     * Strip newlines from the comment so the public key stays single-line.
     */
    private function sanitizeComment(string $comment): string
    {
        return trim(preg_replace('/\s+/', '-', $comment) ?? '');
    }
}
