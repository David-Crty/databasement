<?php

namespace App\Services\Backup\DTO;

/**
 * The options a restore runs with, in the shape stored on a restore and a
 * scheduled restore: empty values are left out.
 */
readonly class RestoreOptions
{
    public ?string $ownerUser;

    public function __construct(
        public bool $forceDatabase = false,
        ?string $ownerUser = null,
        public bool $parallelRestore = false,
    ) {
        $ownerUser = trim((string) $ownerUser);
        $this->ownerUser = $ownerUser !== '' ? $ownerUser : null;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        $ownerUser = $options['owner_user'] ?? null;

        return new self(
            forceDatabase: filter_var($options['force_database'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ownerUser: is_string($ownerUser) ? $ownerUser : null,
            parallelRestore: filter_var($options['parallel_restore'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /**
     * @return array{force_database?: true, owner_user?: string, parallel_restore?: true}
     */
    public function toArray(): array
    {
        return array_filter([
            'force_database' => $this->forceDatabase ?: null,
            'owner_user' => $this->ownerUser,
            'parallel_restore' => $this->parallelRestore ?: null,
        ]);
    }
}
