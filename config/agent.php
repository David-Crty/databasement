<?php

return [
    'url' => env('DATABASEMENT_URL'),
    'token' => env('DATABASEMENT_AGENT_TOKEN'),
    'poll_interval' => (int) env('DATABASEMENT_AGENT_POLL_INTERVAL', 5),
    'lease_duration' => 300, // 5 minutes
];
