<?php

declare(strict_types=1);

return [
    // Advisory poll interval (seconds) returned to the mobile client.
    'poll_interval_seconds' => 5,

    // Default pagination size for list endpoints.
    'default_page_size' => 25,

    // Token lifetime in minutes (null = use Sanctum's default / non-expiring).
    'token_ttl' => null,

    // Default role -> ability map, keyed by Str::slug(role name).
    // Every authenticated role additionally receives 'devices.write'.
    // superadmin/admin (legacy string role) always receive ALL abilities.
    'role_abilities' => [
        'eigenaar' => [
            'products.read', 'products.write',
            'orders.read', 'orders.write',
            'chat.read', 'chat.reply', 'chat.takeover',
            'dashboard.read',
        ],
        'admin' => [
            'products.read', 'products.write',
            'orders.read', 'orders.write',
            'chat.read', 'chat.reply', 'chat.takeover',
            'dashboard.read',
        ],
        'shopbeheerder' => [
            'products.read', 'products.write',
            'orders.read', 'orders.write',
            'dashboard.read',
        ],
        'support-agent' => [
            'chat.read', 'chat.reply', 'chat.takeover',
            'orders.read',
            'dashboard.read',
        ],
        'read-only' => [
            'products.read',
            'orders.read',
            'chat.read',
            'dashboard.read',
        ],
    ],
];
