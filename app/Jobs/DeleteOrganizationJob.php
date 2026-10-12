<?php

namespace App\Jobs;

use App\Actions\Organization\DeleteOrganizationAction;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteOrganizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public string $organizationId,
        public ?int $actorUserId = null,
        public bool $keepFiles = false,
    ) {}

    public function handle(): void
    {
        $organization = Organization::withoutGlobalScope(OrganizationScope::class)->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        DeleteOrganizationAction::run($organization, $this->actorUserId, $this->keepFiles);
    }
}
