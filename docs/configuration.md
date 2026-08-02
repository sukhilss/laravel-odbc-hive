# Configuration

This page covers everything needed to wire up the `hive` connection: the
config file, its keys, the DSN formats the connector accepts, and how to run
Hive alongside another database as your application's default connection —
the common case, since most Laravel apps default to MySQL or PostgreSQL and
add Hive as a secondary connection for analytics workloads.

For known gaps and rough edges (dropped column modifiers, the identifier
character restriction, `charset()` vs `delimiter()` precedence, and more),
see [`limitations.md`](limitations.md).

## The package registers a `hive` connection automatically

`HiveServiceProvider` is picked up by Laravel's package auto-discovery
(`composer.json`'s `extra.laravel.providers`). On boot, it does two things:

1. Merges the package's own `config/hive.php` under the `hive` config key
   (`mergeConfigFrom`), so `config('hive.connections.hive...')` resolves even
   if you never publish anything.
2. Copies `config('hive.connections')` into `database.connections`, with any
   connection your application already defines under that name taking
   precedence (`HiveServiceProvider::registerPackageConnections()`).

The practical effect: as soon as the package is installed and you set the
`HIVE_DSN` environment variable (see below), `DB::connection('hive')` and
`Schema::connection('hive')` resolve to a working `HiveConnection` — no
publishing and no edits to `config/database.php` required. This holds
regardless of what `DB_CONNECTION` (your application's default) is set to.

## Publishing the config file

You only need to publish the config file if you want to change something the
environment variables below don't cover (e.g. hard-code a value rather than
read it from `.env`, or add a second Hive connection under a different name).

```bash
php artisan vendor:publish --tag=hive-config
```

The tag is **`hive-config`**, not `config` — verified directly against the
`publishes()` call in `src/HiveServiceProvider.php`:

```php
$this->publishes([$this->configPath() => config_path('hive.php')], 'hive-config');
```

Running the command with the wrong tag (e.g. plain `--tag=config`, which is
what a similarly-named package from a different ecosystem might use) matches
nothing and publishes no file, with no error — so if `config/hive.php` isn't
appearing in your application after running `vendor:publish`, check the tag
first.

Publishing copies the package's `config/hive.php` to your application's
`config/hive.php`, verbatim:

```php
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
```

## Config keys

All keys live under `hive.connections.hive` (or, once merged, under
`database.connections.hive`):

| Key | Env var | Default | Notes |
|---|---|---|---|
| `driver` | — | `hive` | **Required, and must be exactly `hive`.** This is how Laravel's `ConnectionFactory` picks up the `db.connector.hive` binding and the `hive` connection resolver registered in `HiveServiceProvider::register()`. If this key is missing or misspelled, the connection cannot be resolved at all — Laravel will look for a connector/resolver under whatever value you did put here (or fail on a missing `driver` key) instead. This exact failure shipped in v6: the published default config omitted `driver`, so the package's own connection could never resolve even with a correct DSN. |
| `dsn` | `HIVE_DSN` | `''` (empty string) | The ODBC DSN. See "DSN formats" below for exactly what the connector does with this value. |
| `username` | `HIVE_USERNAME` | `''` | Passed through to the underlying `PDO` constructor. |
| `password` | `HIVE_PASSWORD` | `''` | Passed through to the underlying `PDO` constructor. |
| `database` | `HIVE_DATABASE` | `'default'` | The Hive database/schema name. |
| `prefix` | — | `''` | Table prefix, applied the same way as Laravel's other SQL drivers. If you use a **dotted** prefix (e.g. `'analytics.'`) to target a non-default Hive database, read the "Dotted table prefixes and joins" section of [`limitations.md`](limitations.md) first — it breaks join `ON` clauses in a way a flat prefix does not. |

This package ships no `.env.example`; add the four `HIVE_*` variables to
your own application's `.env` as needed. `username`/`password` default to
empty strings rather than `null`, matching what the shipped `config/hive.php`
above declares.

## DSN formats

`HiveConnector::getDsn()` was exercised directly against the following
inputs (run via `docker compose run --rm php`, reflecting the protected
method):

```
{"dsn":"Driver=Hive;Host=h"} => 'odbc:Driver=Hive;Host=h'
{"dsn":"odbc:Driver=Hive"} => 'odbc:Driver=Hive'
{"dsn":""} => NULL
[] => NULL
```

and calling `connect([])` (no `dsn` key at all) throws:

```
connect([]): Hive DSN is not configured. Set the "dsn" key on the connection (or the HIVE_DSN environment variable) to an ODBC DSN.
```

So, precisely:

- If `dsn` already starts with `odbc` (e.g. `odbc:Driver=Hive`), it is used
  as-is.
- If `dsn` is any other non-empty string (e.g. `Driver=Hive;Host=h`), the
  connector prepends `odbc:` for you.
- If `dsn` is missing, not a string, or an empty string, `connect()` throws
  `InvalidArgumentException` with the message shown above — there is no
  silent fallback to a default DSN.

In practice this means you can set `HIVE_DSN` to either a bare ODBC
connection string or one that already carries the `odbc:` scheme, and both
work identically.

## Defining the connection directly in `config/database.php`

Auto-registration (above) covers the common case, but if you'd rather see
the `hive` connection declared explicitly alongside your other connections —
or you need more than one Hive connection, e.g. against two different Hive
databases — define it directly:

```php
// config/database.php
'connections' => [
    'mysql' => [
        // ... your default connection ...
    ],

    'hive' => [
        'driver' => 'hive',
        'dsn' => env('HIVE_DSN', ''),
        'username' => env('HIVE_USERNAME', ''),
        'password' => env('HIVE_PASSWORD', ''),
        'database' => env('HIVE_DATABASE', 'default'),
        'prefix' => '',
    ],

    'hive_reporting' => [
        'driver' => 'hive',
        'dsn' => env('HIVE_REPORTING_DSN', ''),
        'username' => env('HIVE_REPORTING_USERNAME', ''),
        'password' => env('HIVE_REPORTING_PASSWORD', ''),
        'database' => env('HIVE_REPORTING_DATABASE', 'default'),
        'prefix' => '',
    ],
],
```

Whatever you define under `database.connections.hive` here wins over the
package's default: `registerPackageConnections()` merges the package's
connections *underneath* your application's, so an app-defined `hive` key
is never overwritten. This is asserted directly by
`tests/Feature/ServiceProviderTest.php::test_application_config_takes_precedence_over_package_defaults`.

A second (or third) connection, like `hive_reporting` above, needs no
special handling on the package side — it's just another array entry using
the same `hive` driver, so the same connector and grammars apply to it.

## Hive as a secondary connection (the common case)

Most applications will not want `DB_CONNECTION=hive` as their default —
Hive is typically an analytics/warehouse store sitting alongside a
transactional database such as MySQL or PostgreSQL. You don't need to touch
`DB_CONNECTION` at all: leave it pointing at your primary database, add the
`HIVE_*` environment variables described above, and reach Hive explicitly by
name wherever you need it:

```php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Queries — executing ->get() requires a live Hive server and ODBC driver,
// neither of which this package bundles (see limitations.md); the query
// itself compiles correctly, verified below.
DB::connection('hive')->table('events')->where('event_date', '2026-07-01')->get();

// Schema (create-only — see limitations.md)
Schema::connection('hive')->create('events', function ($table) {
    $table->string('name');
});

// Inside a model
class Event extends Model
{
    protected $connection = 'hive';
}
```

The query above compiles to plain, placeholder-based SQL just like any other
Laravel connection (only *raw* `DB::statement($sql, $bindings)` calls have
their bindings silently discarded — see "Inserts are inlined literals, not
bound parameters" in [`limitations.md`](limitations.md)). Verified directly
by building a real `HiveQueryGrammar`-backed query builder and calling
`toSql()` — no live connection involved:

```php
$sql = (new Builder($connection, new HiveQueryGrammar($connection), new HiveProcessor))
    ->from('events')
    ->where('event_date', '2026-07-01')
    ->toSql();
```

```
select * from events where event_date = ?
```

This is exactly what `tests/Feature/ServiceProviderTest.php` exercises:
it resolves `DB::connection('hive')` to a real `HiveConnection` instance
by name, never by changing the application's default connection.

## Further reading

[`schema-builder.md`](schema-builder.md) covers the Hive-specific schema
builder API (`HiveBlueprint`) in detail. [`limitations.md`](limitations.md)
documents everything about this driver's behaviour that a real Hive server
has not (yet) been used to verify, plus several confirmed rough edges —
read it before relying on this driver for anything beyond `CREATE TABLE`.
