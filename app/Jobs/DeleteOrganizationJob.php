<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Services\Organization\OrganizationMergeService;
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

    public function handle(OrganizationMergeService $service): void
    {
        $organization = Organization::withoutGlobalScope(OrganizationScope::class)->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $service->delete($organization, $this->actorUserId, $this->keepFiles);
    }
}
