# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Human-facing documentation is canonical and verified — this file deliberately does not duplicate it:

- [`CONTRIBUTING.md`](CONTRIBUTING.md) — workflow, the gates a change must pass, project rules
- [`docs/local-development.md`](docs/local-development.md) — Docker setup, both Laravel majors, the golden harness
- [`docs/limitations.md`](docs/limitations.md) — what this driver cannot do, each item demonstrated
- [`docs/schema-builder.md`](docs/schema-builder.md) / [`docs/configuration.md`](docs/configuration.md) — reference

## What this is

`sukhilss/laravel-odbc-hive` — a Laravel package (library only) adding an Apache Hive driver to
Illuminate's database layer over ODBC/PDO. Requires **PHP ^8.2** and **`illuminate/database` ^11.0 || ^12.0**.
PSR-4 root `Sukhil\Database\Hive\` → `src/`; tests `Sukhil\Database\Hive\Tests\` → `tests/`.

## Commands

**There is no local PHP or Composer.** Everything runs through Docker:

```bash
docker compose run --rm php composer install
docker compose run --rm php composer test      # phpunit
docker compose run --rm php composer lint      # pint --test
docker compose run --rm php composer fix       # pint
docker compose run --rm php composer analyse   # phpstan --memory-limit=1G
```

PHPStan runs at **level 6 with no baseline**. A new violation must be fixed, or suppressed with an
inline comment explaining why the analyser is wrong — never absorbed silently.

## Rules that will bite you

**`PDO::quote()` must never be called anywhere in this package.** PDO_ODBC does not implement it; it
returns `false`, which `implode()` renders as an empty string and emits `values (, 30)`. Value escaping
goes through `Support/HiveValueQuoter`.

**Identifiers are emitted verbatim, not quoted.** Hive's double quotes delimit string literals rather
than identifiers, so the base grammar's `"name"` output is wrong here. `Support/HiveIdentifier` therefore
*validates* (`[A-Za-z0-9_]`, `\z`-anchored) and throws on anything else. **This is the package's primary
SQL-injection defence** — a previous iteration returned identifiers unchanged and was injectable through
`->insert($request->all())` array keys. Do not relax it without understanding that.

**Version-dependent code is confined to exactly four sites.** Nowhere else may branch:

| Site | Mechanism |
|---|---|
| `HiveConnection::configureGrammar` | `IlluminateVersion::usesConnectionAwareSchemaApi()` |
| `HiveSchemaBuilder::createBlueprint` | same |
| `HiveQueryGrammar::__construct` | `method_exists(parent::class, '__construct')` |
| `HiveSchemaGrammar::__construct` | same |

`Support/IlluminateVersion` probes by reflecting `Blueprint::__construct`'s first parameter type rather
than comparing version strings. The grammars cannot use it — a grammar cannot consult it before its own
parent is initialised. New Hive type mappings belong in `HiveSchemaGrammar`, never behind a branch.

**Never hand-edit `tests/fixtures/golden-v6-schema.json`.** If golden parity fails, that is a finding.

## Architecture

`HiveServiceProvider::register()` binds `db.connector.hive` and registers `Connection::resolverFor('hive', …)`;
`ConnectionFactory` picks both up. `boot()` merges `hive.connections` into `database.connections`, with
application config winning. The provider is deliberately **not** deferrable — `register()`'s resolver
registration and `boot()`'s merge are both load-bearing.

`HiveConnection` declares no constructor: the parent accepts `\PDO|(\Closure(): \PDO)` and Laravel passes a
closure for lazy connections. `HiveBlueprint` likewise declares none, because `Blueprint::__construct`'s
signature differs between Laravel 11 and 12.

Six confirmed Laravel 11↔12 divergences are handled: `Blueprint::__construct`, `compileCreate` arity,
base `Grammar::__construct` vs `setConnection()`, `Blueprint::toSql` arity, `Grammar::wrapTable` arity, and
the schema `Builder` resolver's argument shape.

## What is and is not verified

- **No Hive server and no ODBC driver exist in this project.** Cloudera's is proprietary. Nothing here has
  ever executed against real Hive. Correctness rests on unit tests over generated SQL strings.
- The golden-parity harness pins DDL against commit `ea23f65`. It proves **no regression** relative to that
  commit. It proves **nothing** about whether the SQL is valid HiveQL.
- Changes must pass on **both** Laravel majors. `docs/local-development.md` has the procedure; note
  `composer update` needs `--no-blocking` for Laravel 11.

## A history trap

**`v6.0.4` is commit `0a69cf8`. `ea23f65` is untagged master, two commits later, and the two differ
materially.** Released v6.0.4 used *bound parameters*; the `\DB::getPdo()->quote()` code exists only on
those two untagged commits.

Conflating them produced eleven false claims across the documentation before it was caught. When writing
about "what v6 did", check `git show v6.0.4:<path>` — not `ea23f65`.

`tests/fixtures/intentional-deviations.php` still carries that conflation in its reason strings and is
worth correcting.

## Style

Laravel Pint, `laravel` preset (`pint.json`). Every file in `src/` and `tests/` opens with
`declare(strict_types=1);`. Classes and methods carry docblocks — match the surrounding density.
