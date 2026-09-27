<?php

return [
    'enabled' => (bool) env('DATABASEMENT_URL'),
    'url' => env('DATABASEMENT_URL'),
    'token' => env('DATABASEMENT_AGENT_TOKEN'),
    'poll_interval' => max(1, (int) env('DATABASEMENT_AGENT_POLL_INTERVAL', 5)),
    'lease_duration' => 300, // 5 minutes

    // How long the UI waits for an agent to report a volume connection test
    // before giving up on it. Sized to a few poll intervals, since the agent
    // only sees the job on its next poll.
    'volume_test_timeout' => max(10, (int) env('DATABASEMENT_AGENT_VOLUME_TEST_TIMEOUT', 60)),
];
