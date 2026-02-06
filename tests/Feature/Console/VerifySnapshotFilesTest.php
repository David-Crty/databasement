<?php

use App\Jobs\VerifySnapshotFileJob;
use Illuminate\Support\Facades\Queue;

test('dispatches a single verification job', function () {
    Queue::fake();

    $this->artisan('snapshots:verify-files')
        ->expectsOutput('Snapshot file verification job dispatched.')
        ->assertExitCode(0);

    Queue::assertPushed(VerifySnapshotFileJob::class, 1);
    Queue::assertPushed(VerifySnapshotFileJob::class, fn ($job) => $job->snapshotId === null);
});
