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
];
