<?php

namespace App\Jobs;

use App\Actions\Organization\MergeOrganizationsAction;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MergeOrganizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public string $sourceId,
        public string $destinationId,
        public ?int $actorUserId = null,
    ) {}

    public function handle(): void
    {
        $source = Organization::withoutGlobalScope(OrganizationScope::class)->find($this->sourceId);
        $destination = Organization::withoutGlobalScope(OrganizationScope::class)->find($this->destinationId);

        if ($source === null || $destination === null) {
            return;
        }

        MergeOrganizationsAction::run($source, $destination, $this->actorUserId);
    }
}
