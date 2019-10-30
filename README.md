# laravel-odbc-hive
laravel-odbc-hive is a simple hive service provider for Laravel.
It provides hive Connection by extending the Illuminate Database component of the laravel framework.

---

- [Installation](#installation)
- [Configuration](#configuration)

## Installation
Add laravel-db2 to your composer.json file:
```
"require": {
    "sukhilss/laravel-odbc-hive": "^6.0"
}
```
Use [composer](https://getcomposer.org) to install this package.
```
$ composer update
```

### Configuration


#### Configure hive using package config file

Run on the command line from the root of your project:

```
$ php artisan vendor:publish
```

Set your laravel-odbc-hive credentials in ``app/config/hive.php``
the same way as above

Supported DDL commands

```
Schema::connection("hive")->create('dummy_' . time(), function (Blueprint $table) {
    // Numeric Types
    $table->integer('integer_field');
    $table->bigInteger('big_integer');
    $table->smallInteger('small_integer');
    $table->tinyInteger('tinyinteger_field');
    $table->float('float_field');
    $table->double('double_field');
    $table->decimal('decimal_field');

    $table->timestamp('timestamp_field');
    $table->date('date_field');

    // String Types
    $table->string('string_field'); // String literals can be expressed with either single quotes (') or double quotes ("). 
    $table->char('char_field'); // fixed-length strings, the values should be shorter than the specified length
    $table->varChar('varchar_fied'); // varchar between 1 and 65535

    $table->boolean('boolean_field');
    $table->binary('binary_field');
});
```

