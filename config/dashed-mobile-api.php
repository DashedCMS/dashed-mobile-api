<?php

declare(strict_types=1);

return [
    // Advisory poll interval (seconds) returned to the mobile client.
    'poll_interval_seconds' => 5,

    // Default pagination size for list endpoints.
    'default_page_size' => 25,

    // Token lifetime in minutes (null = use Sanctum's default / non-expiring).
    'token_ttl' => null,

    // App-versie-eisen voor de mobiele app. Ops kan deze waarden ophogen om
    // gebruikers tot updaten te bewegen: 'latest' toont een (negeerbare) banner,
    // 'min_supported' (of 'force_update' = true) toont een blokkerende modal.
    'app_version' => [
        'min_supported' => '0.0.0',
        'latest' => '0.0.0',
        'force_update' => false,
        'message' => null,
    ],
];
