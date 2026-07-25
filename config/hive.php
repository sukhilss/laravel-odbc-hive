<?php

declare(strict_types=1);

return [
    'connections' => [
        'hive' => [
            // Required. Without it the connection cannot be resolved.
            'driver' => 'hive',

            // ODBC DSN. The odbc: scheme is added automatically when absent.
            'dsn' => env('HIVE_DSN', ''),

            'username' => env('HIVE_USERNAME', ''),
            'password' => env('HIVE_PASSWORD', ''),
            'database' => env('HIVE_DATABASE', 'default'),
            'prefix' => '',
        ],
    ],
];
