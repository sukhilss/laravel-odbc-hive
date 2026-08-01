# laravel-odbc-hive

Apache Hive database driver for Laravel, over ODBC/PDO. Registers a `hive`
connection you can use alongside your application's default database —
query it with the standard query builder or Eloquent, and create tables
with a Hive-specific `Schema` builder.

[![CI](https://github.com/sukhilss/laravel-odbc-hive/actions/workflows/ci.yml/badge.svg)](https://github.com/sukhilss/laravel-odbc-hive/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/sukhilss/laravel-odbc-hive.svg)](https://packagist.org/packages/sukhilss/laravel-odbc-hive)
[![Total Downloads](https://img.shields.io/packagist/dt/sukhilss/laravel-odbc-hive.svg)](https://packagist.org/packages/sukhilss/laravel-odbc-hive)
[![License](https://img.shields.io/packagist/l/sukhilss/laravel-odbc-hive.svg)](LICENSE.md)

## Requirements

| Package | Laravel | PHP |
|---|---|---|
| v7.x (this branch) | 11.x, 12.x | 8.2+ |
| v6.x | 6.x | 7.2+ |

If you're on Laravel 6, stay on `^6.0` of this package — see the
[`v6.0.4` tag](https://github.com/sukhilss/laravel-odbc-hive/tree/v6.0.4).
Everything below documents v7.

## What you need that this package does not provide

**A Hive ODBC driver.** This package talks to Hive over `PDO_ODBC`, which
needs an ODBC driver installed on the machine running your application —
Cloudera's, the one most commonly used in practice, is **proprietary** and
cannot be bundled with this package or installed via Composer. Installing
this package alone will not get you a working connection. See
[`docs/local-development.md`](docs/local-development.md) for how the
driver fits in (and how to develop against this repository without one).

## Installation

```bash
composer require sukhilss/laravel-odbc-hive
```

**v7.0.0 is not yet tagged/published**, so the command above currently
resolves to v6.0.4 (Laravel 6, PHP 7.2+) — not what this document
describes. Until the tag lands, install the v7 code from this branch
instead, and switch to `^7.0` once it's released:

```bash
composer require sukhilss/laravel-odbc-hive:dev-feature/v7-laravel-11-12-port
```

Laravel's package auto-discovery picks up `HiveServiceProvider`
automatically (`composer.json`'s `extra.laravel.providers`) — no manual
registration needed. As soon as you set `HIVE_DSN` (below), `DB::connection('hive')`
and `Schema::connection('hive')` resolve to a working connection.

To customize the config instead of relying on environment variables alone,
publish it:

```bash
php artisan vendor:publish --tag=hive-config
```

This copies the package's `config/hive.php` to your application's
`config/hive.php`. See [`docs/configuration.md`](docs/configuration.md) for
every key, the DSN formats accepted, and how to run Hive as a secondary
connection alongside MySQL/PostgreSQL — the common case.

## Quick start

Set the connection in `.env`:

```
HIVE_DSN=Driver=Hive;Host=your-host;Port=10000
HIVE_USERNAME=
HIVE_PASSWORD=
HIVE_DATABASE=default
```

Query it like any other Laravel connection:

```php
use Illuminate\Support\Facades\DB;

DB::connection('hive')->table('events')->where('event_date', '2026-07-01')->get();
```

Executing `->get()` needs a live Hive server and ODBC driver, neither of
which this repository has. The query still compiles correctly without one
— verified by building a real `HiveQueryGrammar`-backed query builder and
calling `->toSql()`:

```
select * from events where event_date = ?
```

Create a table with the Hive-specific schema builder:

```php
use Illuminate\Support\Facades\Schema;
use Sukhil\Database\Hive\Schema\HiveBlueprint;

Schema::connection('hive')->create('events', function (HiveBlueprint $table) {
    $table->string('name');
    $table->storedAs('ORC')->location('/warehouse/events');
});
```

Compiled directly through `HiveSchemaGrammar` (again, no live connection
needed — schema compilation is pure string generation):

```sql
create table events (name string) STORED AS ORC LOCATION '/warehouse/events'
```

See [`docs/schema-builder.md`](docs/schema-builder.md) for the full column
type mapping and every Hive-specific option.

## Documentation

- [`docs/configuration.md`](docs/configuration.md) — connection setup, config
  keys, DSN formats, running Hive as a secondary connection.
- [`docs/schema-builder.md`](docs/schema-builder.md) — the Hive-specific
  schema builder API and column type mapping.
- [`docs/limitations.md`](docs/limitations.md) — everything this driver does
  not do, or does differently than you'd expect: dropped column modifiers,
  CREATE-only schema support, unbound raw statement parameters, and more.
  Read this before relying on the driver for anything beyond basic
  `CREATE TABLE` and `SELECT`.
- [`docs/local-development.md`](docs/local-development.md) — building,
  testing, and linting this package, and how the Docker toolchain (and the
  ODBC driver gap) fits together.

## Troubleshooting

**"Hive DSN is not configured"** — thrown by `HiveConnector::connect()`
when the `dsn` config key (or `HIVE_DSN` env var) is missing or empty:

```
InvalidArgumentException: Hive DSN is not configured. Set the "dsn" key on
the connection (or the HIVE_DSN environment variable) to an ODBC DSN.
```

Set `HIVE_DSN` in `.env`, or the `dsn` key if you've published the config.

**"Unsafe Hive identifier"** — thrown by `HiveIdentifier` when a table or
column name contains anything other than letters, digits, and underscores
(Hive identifiers are never quoted by this driver — see
[`docs/limitations.md`](docs/limitations.md)):

```
InvalidArgumentException: Unsafe Hive identifier 'my-table': only letters,
digits and underscores are permitted.
```

Rename the table/column, or sanitize any user-supplied name before it
reaches the query builder.

**"could not find driver"** — a plain `PDOException` thrown by PDO itself,
not by this package, when the `pdo_odbc` extension isn't installed on the
machine trying to connect:

```
PDOException: could not find driver
```

Install `pdo_odbc` (and an ODBC driver for Hive — see "What you need that
this package does not provide" above). `composer.json` only *suggests*
`ext-odbc`, since this package cannot install a Hive ODBC driver for you.

## Contributing

Bug reports and pull requests are welcome. See
[`docs/local-development.md`](docs/local-development.md) for how to build,
test, and lint this package before submitting a PR — the full local gate is
`composer test && composer lint && composer analyse`, all run through
`docker compose run --rm php`.

## Security

If you find a security issue, please report it privately (via GitHub's
security advisory feature on this repository) rather than opening a public
issue.

## License

MIT. See [`LICENSE.md`](LICENSE.md).
