<?php

namespace App\Actions\Restore;

use App\Models\Restore;
use App\Services\NotificationService;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class CompleteRestoreAction
{
    use AsFake, AsObject;

    public function __construct(
        private NotificationService $notificationService,
    ) {}

    public function handle(Restore $restore): void
    {
        $restore->job->markCompleted();

        $this->notificationService->notifyRestoreSuccess($restore);
    }
}
