<?php

use App\Jobs\CleanupExpiredSnapshotsJob;
use Illuminate\Support\Facades\Queue;

test('dispatches a cleanup job', function () {
    Queue::fake();

    $this->artisan('snapshots:cleanup')
        ->expectsOutput('Snapshot cleanup job dispatched.')
        ->assertExitCode(0);

    Queue::assertPushed(CleanupExpiredSnapshotsJob::class, 1);
});
