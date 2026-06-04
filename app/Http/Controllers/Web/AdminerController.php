<?php

namespace App\Http\Controllers\Web;

use App\Enums\DatabaseType;
use App\Http\Controllers\Controller;
use App\Models\DatabaseServer;
use App\Services\AdminerService;
use Illuminate\Support\Facades\Gate;

class AdminerController extends Controller
{
    public function __invoke(AdminerService $adminer): void
    {
        Gate::authorize('adminer', DatabaseServer::class);

        $credentials = null;
        $serverId = session()->pull('adminer_server_id');

        // Initial load: build credentials from the database server stored in session
        if ($serverId) {
            /** @var DatabaseServer $server */
            $server = DatabaseServer::findOrFail($serverId);
            abort_unless($server->supportsAdminer(), 403);

            $credentials = $this->buildCredentials($server);
            $_POST['auth'] = $credentials;
        }

        // Release the session lock before Adminer runs. Adminer is long-lived
        // and loads sub-resources (CSS/JS) through this same route — holding
        // the lock would block those requests and cause timeouts.
        session()->save();

        $adminer->render($credentials);
    }

    /**
     * @return array{driver: string, server: string, username: string, password: string, db: string}
     */
    private function buildCredentials(DatabaseServer $server): array
    {
        $driver = match ($server->database_type) { // @phpstan-ignore match.unhandled (supportsAdminer() filters unsupported types)
            DatabaseType::MYSQL => 'server',
            DatabaseType::POSTGRESQL => 'pgsql',
            DatabaseType::SQLITE => 'sqlite',
        };

        $serverAddress = $server->database_type === DatabaseType::SQLITE
            ? ''
            : $server->host.':'.$server->port;

        $db = '';
        $databaseNames = $server->resolveDatabaseNames();
        if (count($databaseNames) === 1) {
            $db = $databaseNames[0];
        }

        $user = auth()->user();
        $useDemoCreds = $user instanceof \App\Models\User && $user->isDemo();

        return [
            'driver' => $driver,
            'server' => $serverAddress,
            'username' => $useDemoCreds
                ? (string) config('services.adminer.demo_username')
                : ($server->username ?? ''),
            'password' => $useDemoCreds
                ? (string) config('services.adminer.demo_password')
                : $server->getDecryptedPassword(),
            'db' => $db,
        ];
    }
}
