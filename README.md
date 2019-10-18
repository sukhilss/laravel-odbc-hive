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

Set your laravel-db2 credentials in ``app/config/db2.php``
the same way as above

