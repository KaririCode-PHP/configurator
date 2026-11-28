<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'TestApp',
        'version' => '4.0.0',
        'debug' => true,
        'port' => 8080,
        'rate' => 1.5,
    ],
    'database' => [
        'driver' => 'pgsql',
        'host' => 'localhost',
        'port' => 5432,
        'name' => 'test_db',
        'options' => [
            'charset' => 'utf8',
            'timezone' => 'UTC',
        ],
    ],
    'cache' => [
        'driver' => 'file',
        'ttl' => 3600,
    ],
    'middleware' => ['auth', 'cors', 'logging'],
];
