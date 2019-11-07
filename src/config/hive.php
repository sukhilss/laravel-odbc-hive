<?php

return [
    'connections' => [
        'hive' => [
            'dsn' => env('HIVE_DSN', ''),
            'username' => env('HIVE_USERNAME', ''),
            'password' => env('HIVE_PASSWORD', ''),
            'database' => env('HIVE_DATABASE', ''),
            'prefix' => '',
        ]
    ]
];
