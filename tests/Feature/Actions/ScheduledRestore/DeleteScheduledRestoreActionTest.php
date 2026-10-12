<?php

use App\Actions\ScheduledRestore\DeleteScheduledRestoreAction;
use App\Models\ScheduledRestore;

test('it deletes the scheduled restore', function () {
    $scheduledRestore = createScheduledRestore();

    DeleteScheduledRestoreAction::run($scheduledRestore);

    expect(ScheduledRestore::find($scheduledRestore->id))->toBeNull();
});
