<?php

return [
    'enabled' => (bool) env('DATABASEMENT_URL'),
    'url' => env('DATABASEMENT_URL'),
    'token' => env('DATABASEMENT_AGENT_TOKEN'),
    'poll_interval' => max(1, (int) env('DATABASEMENT_AGENT_POLL_INTERVAL', 5)),
    // Size of each request when uploading a file through the server. 16 MB
    // cuts the request count by four against 4 MB (a 10 GB backup takes ~640
    // requests) while staying well under the server image's post_max_size
    // (PHP_POST_MAX_SIZE, 64M by default). Larger chunks barely add speed, since
    // bandwidth dominates, but cost memory (the agent holds the chunk, and the
    // HTTP client may copy it), resend more after a dropped connection, and
    // hit more proxy/WAF body limits (often 10-32 MB).
    'relay_chunk_bytes' => max(1, (int) env('DATABASEMENT_AGENT_RELAY_CHUNK_MB', 16)) * 1024 * 1024,
];
