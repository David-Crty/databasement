<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\DatabaseServer\CreateSshConfigAction;
use App\Actions\DatabaseServer\DeleteSshConfigAction;
use App\Actions\DatabaseServer\UpdateSshConfigAction;
use App\Exceptions\DatabaseServer\SshConfigInUseException;
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
        $perPage = $this->perPage($request);

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

        $sshConfig = CreateSshConfigAction::run(app(CurrentOrganization::class)->model(), $validated);

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
        $publicKey = $this->generateKeyIfRequested($request, $keyGenerator, $validated);

        UpdateSshConfigAction::run($databaseServerSshConfig, $validated);

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

        try {
            DeleteSshConfigAction::run($databaseServerSshConfig);
        } catch (SshConfigInUseException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

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
}
