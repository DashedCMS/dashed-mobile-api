<?php

declare(strict_types=1);

return [
    // Advisory poll interval (seconds) returned to the mobile client.
    'poll_interval_seconds' => 5,

    // Default pagination size for list endpoints.
    'default_page_size' => 25,

    // Token lifetime in minutes (null = use Sanctum's default / non-expiring).
    'token_ttl' => null,
];
