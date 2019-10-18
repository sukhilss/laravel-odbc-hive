<?php

/*
|--------------------------------------------------------------------------
| Hive Config
|--------------------------------------------------------------------------

* PDO::ATTR_CASE:
PDO::CASE_LOWER
PDO::CASE_UPPER
PDO::CASE_NATURAL

* PDO::I5_ATTR_DBC_SYS_NAMING
true
false

* PDO::I5_ATTR_COMMIT
PDO::I5_TXN_READ_COMMITTED
PDO::I5_TXN_READ_UNCOMMITTED
PDO::I5_TXN_REPEATABLE_READ
PDO::I5_TXN_SERIALIZABLE
PDO::I5_TXN_NO_COMMIT

* PDO::I5_ATTR_DBC_LIBL
,
* PDO::I5_ATTR_DBC_CURLIB,

*/

return [
    'connections' => [
        'hive' => [
            'dsn' => env('HIVE_DSN', ''),
            'username' => env('HIVE_USERNAME', ''),
            'password' => env('HIVE_PASSWORD', ''),
            'database' => env('HIVE_DATABASE', ''),
            'prefix' => '',
            'schema' => 'default schema',
            'date_format' => 'Y-m-d H:i:s',
            'options' => [
                PDO::ATTR_CASE => PDO::CASE_LOWER,
                PDO::ATTR_PERSISTENT => false,
                PDO::I5_ATTR_DBC_SYS_NAMING => false,
                PDO::I5_ATTR_COMMIT => PDO::I5_TXN_NO_COMMIT,
                PDO::I5_ATTR_JOB_SORT => false,
                PDO::I5_ATTR_DBC_LIBL => '',
                PDO::I5_ATTR_DBC_CURLIB => '',
            ],
        ]
    ]
];
