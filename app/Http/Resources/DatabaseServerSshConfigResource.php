<?php

namespace App\Http\Resources;

use App\Models\DatabaseServerSshConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DatabaseServerSshConfig
 */
class DatabaseServerSshConfigResource extends JsonResource
{
    /**
     * The public key is only known right after a server-side keypair
     * generation — it is never stored, so it is returned once and only on the
     * response that generated it.
     *
     * Set through a setter rather than the constructor: resource collections
     * are built with mapInto(), which passes the collection key as a second
     * constructor argument.
     */
    private ?string $publicKey = null;

    public function withPublicKey(?string $publicKey): static
    {
        $this->publicKey = $publicKey;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'auth_type' => $this->auth_type,
            'compression' => $this->compression,
            ...($this->publicKey !== null ? ['public_key' => $this->publicKey] : []),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
