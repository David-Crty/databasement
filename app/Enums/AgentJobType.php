<?php

namespace App\Enums;

use App\Services\Agent\Handlers\AgentJobHandler;
use App\Services\Agent\Handlers\BackupJobHandler;
use App\Services\Agent\Handlers\DiscoveryJobHandler;
use App\Services\Agent\Handlers\RestoreJobHandler;

enum AgentJobType: string
{
    case Backup = 'backup';
    case Discover = 'discover';
    case Restore = 'restore';

    /**
     * The app-side handler that owns this job type's lifecycle.
     */
    public function handler(): AgentJobHandler
    {
        return app(match ($this) {
            self::Backup => BackupJobHandler::class,
            self::Discover => DiscoveryJobHandler::class,
            self::Restore => RestoreJobHandler::class,
        });
    }

    /**
     * Job types an agent that predates restore support can run. It claims
     * without advertising any, so these are all it is ever handed.
     *
     * @return list<string>
     */
    public static function legacyValues(): array
    {
        return [self::Backup->value, self::Discover->value];
    }
}
