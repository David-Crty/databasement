<?php

return [
    'enabled' => (bool) env('DATABASEMENT_URL'),
    'url' => env('DATABASEMENT_URL'),
    'token' => env('DATABASEMENT_AGENT_TOKEN'),
    'poll_interval' => max(1, (int) env('DATABASEMENT_AGENT_POLL_INTERVAL', 5)),
    'lease_duration' => 300, // 5 minutes
    // Max seconds to wait when relaying a backup archive to the main server.
    'upload_timeout' => max(60, (int) env('DATABASEMENT_AGENT_UPLOAD_TIMEOUT', 3600)),
    // How many times to attempt a relay upload before giving up (retries cover
    // transient network/5xx failures only).
    'upload_retries' => max(1, (int) env('DATABASEMENT_AGENT_UPLOAD_RETRIES', 3)),
    // Upper bound for an agent-requested lease. Relay uploads can run for a
    // whole upload_timeout, so the agent asks for a longer lease before each
    // attempt to avoid being reclaimed mid-transfer; the server clamps it here.
    'max_lease_duration' => (int) env(
        'DATABASEMENT_AGENT_MAX_LEASE_DURATION',
        max(600, (int) env('DATABASEMENT_AGENT_UPLOAD_TIMEOUT', 3600) + 300),
    ),
];
