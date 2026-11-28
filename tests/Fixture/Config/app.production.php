<?php

declare(strict_types=1);

return [
    'app' => [
        'debug' => false,
    ],
    'database' => [
        'host' => 'db.prod.example.com',
    ],
    'cache' => [
        'driver' => 'redis',
        'ttl' => 86400,
    ],
    'middleware' => ['auth', 'cors', 'logging', 'rate-limiter'],
];
