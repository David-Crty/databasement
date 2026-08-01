<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveDatabaseServerSshConfigRequest;
use App\Http\Resources\DatabaseServerSshConfigResource;
use App\Models\DatabaseServerSshConfig;
use App\Services\CurrentOrganization;
use App\Services\SshKeyGenerator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * @tags Database Server SSH Configs
 */
class DatabaseServerSshConfigController extends Controller
{
    use AuthorizesRequests;

    /**
     * List all SSH tunnel configurations.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = max(1, min($request->integer('per_page', 15), 100));

        $sshConfigs = DatabaseServerSshConfig::query()
            ->orderBy('host')
            ->paginate($perPage);

        return DatabaseServerSshConfigResource::collection($sshConfigs);
    }

    /**
     * Get an SSH tunnel configuration.
     */
    public function show(DatabaseServerSshConfig $databaseServerSshConfig): DatabaseServerSshConfigResource
    {
        $this->authorize('view', $databaseServerSshConfig);

        return new DatabaseServerSshConfigResource($databaseServerSshConfig);
    }

    /**
     * Create an SSH tunnel configuration.
     *
     * Pass `generate_key: true` with `auth_type: key` to have the keypair
     * generated server-side. The public key is then returned once, on this
     * response only — it is not stored and never returned again.
     *
     * @response 201
     */
    public function store(SaveDatabaseServerSshConfigRequest $request, SshKeyGenerator $keyGenerator): JsonResponse
    {
        $this->authorize('create', DatabaseServerSshConfig::class);

        $validated = $request->validated();
        $publicKey = $this->generateKeyIfRequested($request, $keyGenerator, $validated);

        $validated['port'] ??= 22;
        $validated['organization_id'] = app(CurrentOrganization::class)->id();

        $sshConfig = DatabaseServerSshConfig::create($this->clearUnusedCredentials($validated));

        return new DatabaseServerSshConfigResource($sshConfig)
            ->withPublicKey($publicKey)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update an SSH tunnel configuration.
     *
     * Credentials are write-only: omit `password` / `private_key` to keep the
     * stored ones. Pass `generate_key: true` to replace the stored private key
     * with a freshly generated one and get the new public key back.
     */
    public function update(
        SaveDatabaseServerSshConfigRequest $request,
        DatabaseServerSshConfig $databaseServerSshConfig,
        SshKeyGenerator $keyGenerator
    ): DatabaseServerSshConfigResource {
        $this->authorize('update', $databaseServerSshConfig);

        $validated = $request->validated();

        // Blank credentials mean "keep the stored one" — they are never
        // readable back through the API, so a client cannot echo them. This
        // runs before key generation, whose deliberate blanks must survive.
        foreach (DatabaseServerSshConfig::SENSITIVE_FIELDS as $field) {
            if (array_key_exists($field, $validated) && blank($validated[$field])) {
                unset($validated[$field]);
            }
        }

        // A client-supplied private key invalidates the passphrase held for the
        // one it replaces, so that passphrase is dropped unless the request
        // supplies one for the new key.
        if (filled($validated['private_key'] ?? null) && ! array_key_exists('key_passphrase', $validated)) {
            $validated['key_passphrase'] = null;
        }

        $publicKey = $this->generateKeyIfRequested($request, $keyGenerator, $validated);

        $validated['port'] ??= $databaseServerSshConfig->port;

        $databaseServerSshConfig->update($this->clearUnusedCredentials($validated));

        return new DatabaseServerSshConfigResource($databaseServerSshConfig)
            ->withPublicKey($publicKey);
    }

    /**
     * Delete an SSH tunnel configuration.
     *
     * Configurations still attached to a database server cannot be deleted.
     *
     * @response 204
     */
    public function destroy(DatabaseServerSshConfig $databaseServerSshConfig): Response|JsonResponse
    {
        $this->authorize('delete', $databaseServerSshConfig);

        if ($databaseServerSshConfig->databaseServers()->exists()) {
            return response()->json([
                'message' => 'This SSH configuration is still used by one or more database servers.',
            ], 422);
        }

        $databaseServerSshConfig->delete();

        return response()->noContent();
    }

    /**
     * Generate a keypair server-side when the payload asks for one, writing the
     * private key into it. A generated key never has a passphrase, so any
     * stored one is dropped. Returns the public key, which is not persisted.
     *
     * @param  array<string, mixed>  $validated
     */
    private function generateKeyIfRequested(Request $request, SshKeyGenerator $keyGenerator, array &$validated): ?string
    {
        unset($validated['generate_key']);

        if (! $request->boolean('generate_key')) {
            return null;
        }

        /** @var string $host */
        $host = $validated['host'];
        $keypair = $keyGenerator->generate($keyGenerator->buildComment('', $host));

        $validated['private_key'] = $keypair['private'];
        $validated['key_passphrase'] = null;

        return $keypair['public'];
    }

    /**
     * Wipe the credentials belonging to the auth type that is not in use, so a
     * config never keeps a stale password next to a private key.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clearUnusedCredentials(array $data): array
    {
        if ($data['auth_type'] === 'password') {
            $data['private_key'] = null;
            $data['key_passphrase'] = null;
        } else {
            $data['password'] = null;
        }

        return $data;
    }
}
