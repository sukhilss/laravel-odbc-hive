# Modernizing laravel-odbc-hive for Laravel 11 & 12

**Date:** 2026-07-25
**Status:** Approved
**Target release:** v7.0.0

## Summary

`sukhilss/laravel-odbc-hive` is a Laravel package, last touched in 2019, that adds an Apache Hive
driver to Illuminate's database layer over ODBC/PDO. It targets Laravel 6 and PHP 7.2, both long EOL.

This spec covers porting it to Laravel 11 and 12 on PHP 8.2+, fixing five latent defects found during
analysis, adding a test suite and CI, and bringing the repository up to open-source standards.

## Context and constraints

Three constraints shape every decision below.

**No open-source Hive ODBC driver exists.** Cloudera's driver is a free-but-proprietary download
requiring EULA acceptance. A public repository cannot legally redistribute it, so `docker compose up`
can never produce a working Hive connection unattended.

**No live Hive environment is available for validation.** Verification is limited to unit tests over
generated SQL strings, plus mocked-PDO feature tests.

**No PHP toolchain exists on the development machine.** All local execution must go through Docker.

Together these mean: we cannot prove the driver works against real Hive. We *can* prove we did not
regress against v6, and that is the bar this spec sets.

## What actually changed in Illuminate

Verified against the `11.x` and `12.x` branches of `laravel/framework`, not from memory. Laravel 11
retains the Laravel 6 signatures; **Laravel 12 is where they broke.**

| API | Laravel 11 | Laravel 12 |
|---|---|---|
| `Blueprint::__construct` | `($table, ?Closure $callback = null, $prefix = '')` | `(Connection $connection, $table, ?Closure $callback = null)` |
| `SchemaGrammar::compileCreate` | `(Blueprint, Fluent, Connection)` | `(Blueprint, Fluent)` |
| Grammar construction | `$this->withTablePrefix(new Grammar)` | `new Grammar($this)` |
| `withTablePrefix()` | exists | **removed** |
| `$serials` on schema Grammar | exists | **removed** |

Also confirmed, and relevant to fixes below:

- `Connection::__construct($pdo, ...)` documents `$pdo` as `\PDO|(\Closure(): \PDO)`.
- `ConnectionFactory::createConnector()` resolves the container binding `db.connector.{driver}`.
- `ConnectionFactory::createConnection()` consults `Connection::getResolver($driver)` first.

## Architecture

### The compatibility layer collapses to two files

The initial instinct was parallel per-version class hierarchies. Examining each divergence, most do not
need one:

| Divergence | Parallel classes? | Resolution |
|---|---|---|
| Grammar constructor | No | Branch at the construction site in `HiveConnection` |
| `Blueprint::__construct` | No | Do not override it; branch in `createBlueprint` |
| `compileCreate` arity | No | One widened signature satisfies both parents |
| `$serials` removed | No | Do not declare it |
| Grammar needs PDO to quote | No | Grammar holds its own `Connection` property |

The `compileCreate` case relies on PHP permitting a child to widen a signature. A single declaration is
compatible with both parents:

```php
public function compileCreate(
    Blueprint $blueprint,
    Fluent $command,
    ?Connection $connection = null
): string
```

Against Laravel 11 (parent: three required params) the child supplies three, the third merely optional —
permitted widening. Against Laravel 12 (parent: two params) the extra optional param is likewise
permitted.

`HiveBlueprint` today overrides `__construct` only to forward to `parent::__construct`. Deleting that
override means the class never touches the signature that changed, so one class serves both versions.

**Version branching therefore exists in exactly two files**, both delegating to `Support\IlluminateVersion`.

### File layout

```
src/
  HiveServiceProvider.php                 rewritten: resolverFor + db.connector.hive
  HiveConnection.php                      branches: grammar construction
  Connectors/HiveConnector.php
  Query/Grammars/HiveQueryGrammar.php     holds own Connection
  Query/Processors/HiveProcessor.php
  Schema/HiveSchemaBuilder.php            branches: blueprint construction
  Schema/HiveBlueprint.php                no constructor override
  Schema/Grammars/HiveSchemaGrammar.php   widened compileCreate
  Support/IlluminateVersion.php           the only version_compare in the codebase
  Support/HiveTableOptions.php            typed replacement for dynamic properties
  Support/HiveValueQuoter.php             Hive C-style escaping; replaces PDO::quote()
config/hive.php                           moved out of src/
```

`IlluminateVersion` is injectable rather than static so tests can force either code path under whichever
Laravel version is actually installed.

### Table options become explicit

Today `compileCreate` reads `$blueprint->charset`, `->format`, `->delimiter` and `->location` — dynamic
properties that work only because PHP 7 permitted creating them on the fly. **PHP 8.2 deprecates dynamic
properties**, so this is a forced change rather than a stylistic one.

```php
Schema::connection('hive')->create('events', function (HiveBlueprint $table) {
    $table->string('name');
    $table->storedAs('ORC')->location('/warehouse/events');
});
```

`HiveTableOptions` holds these as typed values; `HiveBlueprint` exposes `storedAs()`, `location()`,
`delimiter()` and `charset()`.

## Defects fixed

**1. Insert quoting is doubly broken.** See "The `PDO::quote()` trap" below — this defect turned out to
be more serious than a wrong-connection bug, and its fix is not the obvious one.

**2. `HiveConnection::__construct` types `$pdo` too narrowly.** It declares `PDO $pdo`, but Laravel passes
a `Closure` for lazy connections. The override does nothing except forward to the parent, so it is
deleted outright.

Retyping it to `PDO|Closure` is *not* an option: the parent declares `$pdo` untyped, and narrowing a
parameter type in a child violates contravariance and fatals. A `@param \PDO|(\Closure(): \PDO)` docblock
gives PHPStan what it needs.

**3. Published config omits `'driver' => 'hive'`.** The provider's own loop skips any connection without
it, so the shipped default cannot work as published, and the README never mentions adding it. The key is
added, and `mergeConfigFrom` supplies defaults without publishing.

**4. Provider uses an outdated registration idiom.** It iterates `config('database.connections')` at
register time and calls `extend()` per connection. Replaced with the current path:

```php
$this->app->bind('db.connector.hive', HiveConnector::class);

Connection::resolverFor('hive', fn ($pdo, $database, $prefix, $config)
    => new HiveConnection($pdo, $database, $prefix, $config));
```

Merging `hive.connections` into `database.connections` is retained for backward compatibility but moves
to `boot()`. Application-level config continues to win over package defaults.

**5. Column modifiers are silently dropped.** `$modifiers` is empty, so `nullable()`, `default()` and
`unsigned()` vanish without warning. Hive genuinely has no such constraints, so the behavior stays — but
`docs/limitations.md` and the README document precisely what is ignored. Per decision, we do not throw,
because that would break migrations that currently work by having those calls ignored.

## The `PDO::quote()` trap

The most consequential finding of this analysis, and the reason defect 1 is listed above without a fix
inline.

**PDO_ODBC does not implement `PDO::quote()`.** The PHP manual names it explicitly; the method returns
`false` when a driver does not support quoting. The package's entire insert path depends on it:

```php
return is_string($item) ? \DB::getPdo()->quote($item) : $item;
```

Under PDO_ODBC, `quote('Alice')` returns `false`, which `implode()` renders as an empty string:

```sql
insert into users (name, age) values (, 30)   -- syntax error
```

**The wrong-connection bug is load-bearing.** `\DB::getPdo()` returns the *default* connection's PDO —
typically MySQL or Postgres, which do implement `quote()`. That accidental misrouting is the only reason
string inserts ever produce valid SQL today. Where the default connection is itself Hive, or no other
connection exists, string inserts are already broken.

Consequently the obvious fix — routing `quote()` through the Hive connection — would have broken inserts
for every user. Correcting the surface bug exposes a deeper one.

### Resolution

`Support\HiveValueQuoter` implements Hive's own C-style string escaping (`\'`, `\\`, `\n`, `\t`, `\r`)
and replaces `PDO::quote()` entirely. It is driver-independent, deterministic, and fully unit-testable
without a Hive server — which is precisely the property this project needs.

Real prepared statements are the answer the PHP manual recommends, and PDO_ODBC does support them. They
remain out of scope because switching the insert path to bindings is the single change that cannot be
validated without a live Hive ODBC connection. `HiveValueQuoter` is a self-contained unit that a future
bindings implementation can replace cleanly.

## Verification strategy

### The problem

We are rewriting SQL-generating code with no Hive server. If the expected SQL strings in the test suite
are hand-derived by reading the v6 source, a misreading becomes an enshrined, passing test. Such a suite
proves nothing.

### Golden-parity capture — schema grammar only

Laravel 6 runs on PHP 8.0, and the schema grammar is pure string manipulation — `compileCreate` never
uses its `$connection` argument. The current code can therefore be *executed* and its real DDL output
captured.

```
docker compose --profile capture run --rm legacy-capture
  → installs laravel/framework:^6.0 under PHP 8.0
  → runs a fixture set of blueprints through today's schema grammar
  → writes tests/fixtures/golden-v6-schema.json
```

The ported implementation must reproduce that file byte-for-byte, except where behavior changed
deliberately. Each exception is an explicit entry in `tests/fixtures/intentional-deviations.php` with a
reason, reviewed rather than silently drifting.

**The insert path is deliberately excluded from capture.** As established above, v6's insert output is
not a property of this package — it depends on whichever driver the default connection happens to use.
Captured under SQLite it reflects SQLite escaping; under real ODBC it reflects broken SQL. There is no
meaningful golden value to record. That path is instead covered by direct unit tests against
`HiveValueQuoter`, which is stronger verification than capture could have provided.

### What this does and does not prove

It proves no regression in DDL compilation, the surface most vulnerable to silent transcription error
during the port. It does not prove correctness against real Hive — nothing available to us does — and a
faithful reproduction of wrong SQL is still wrong SQL. The harness guards roughly 400 lines of
deterministic type mapping; the intentional-deviations list carries the rest of the verification weight
and must be reviewed on its own merits.

### Test layout

```
tests/
  Unit/Schema/HiveSchemaGrammarTest.php    every type mapping, SerDe/ORC/location
  Unit/Schema/GoldenParityTest.php         asserts against golden-v6-schema.json
  Unit/Query/HiveQueryGrammarTest.php      insert compilation, wrapTable
  Unit/Connectors/HiveConnectorTest.php    DSN building, odbc: prefixing
  Unit/Support/HiveValueQuoterTest.php     escaping: quotes, backslashes, control chars
  Unit/Support/IlluminateVersionTest.php   both branches, forced
  Feature/ServiceProviderTest.php          Testbench: driver registers, config merges
  Feature/SchemaBuilderTest.php            Testbench: blueprint resolution per version
```

Grammar tests need no database — they are string assertions. Feature tests boot a real container via
Orchestra Testbench with a fake PDO and never reach a network.

### Tooling

| Tool | Version | Command |
|---|---|---|
| PHPUnit | ^11.0 | `composer test` |
| Orchestra Testbench | ^9.0 \| ^10.0 | — |
| Laravel Pint | ^1.18 | `composer lint` |
| PHPStan + larastan | ^2.0 / ^3.0 | `composer analyse` |

PHPStan runs at **level 6**, no baseline, with the intent of raising it once the port has settled.
Level 6 enforces missing-type-hint reporting without drowning in the loosely-typed Illuminate internals
this package necessarily overrides. The optional `?Connection $connection = null` parameter still needs
careful null handling on both Laravel versions; that is covered by explicit unit tests rather than by
the analyser.

`phpcs.xml` is removed; Pint replaces it.

## Docker environment

```
compose.yaml
  php             PHP 8.3 + odbc extension + composer      always available
  hive            apache/hive:4.0.0                        profile: hive
  legacy-capture  PHP 8.0 + laravel/framework ^6.0         profile: capture
docker/
  php/Dockerfile
  drivers/          gitignored; the Cloudera tarball is placed here manually
```

`docker compose run --rm php composer test` works with zero setup and is the primary development loop.
The `hive` profile requires the operator to supply the proprietary ODBC driver themselves;
`docs/local-development.md` documents the steps.

## CI

Both Laravel 11 and 12 require PHP 8.2+, so the matrix needs no exclusions:

```yaml
matrix:
  php: [8.2, 8.3, 8.4]
  laravel: ['11.*', '12.*']
  deps: [prefer-lowest, prefer-stable]
```

Twelve test jobs, plus single non-matrix jobs for Pint, PHPStan, and coverage. `prefer-lowest` is
included specifically because it catches under-specified version constraints in `composer.json`.

## Documentation

`LICENSE.md` is currently **0 bytes**. `composer.json` declares MIT, so the package has been distributed
for six years with no license text — due diligence would treat it as unlicensed. A real MIT license with
a copyright line is added.

- `README.md` — badges, requirements matrix, install, quick start, troubleshooting, links into `docs/`
- `CHANGELOG.md` — Keep a Changelog format
- `UPGRADE.md` — v6 → v7 migration
- `docs/configuration.md` — DSN formats, env vars, multi-connection setups
- `docs/schema-builder.md` — full type mapping table, table options
- `docs/limitations.md` — what Hive cannot do, what we drop and why
- `docs/local-development.md` — Docker workflows, ODBC driver setup

Repository standards: `CONTRIBUTING.md` (including the note that new type mappings belong in the grammar,
not the version adapters), `CODE_OF_CONDUCT.md` (Contributor Covenant 2.1), `SECURITY.md`,
`.github/ISSUE_TEMPLATE/` (bug, feature), `.github/PULL_REQUEST_TEMPLATE.md`, `.github/workflows/ci.yml`.

`.gitattributes` marks `tests/`, `docker/`, `docs/` and `.github/` as `export-ignore` to slim the
distributed package. `.gitignore` gains `/vendor`, `composer.lock`, `.phpunit.cache`, `docker/drivers/*`.

## Versioning

Plain semver from here; the package major no longer tracks the Laravel major, since one release now spans
two.

`v7.0.0` is not a free choice. The package is published on Packagist, where versions must increase
monotonically for existing users to receive updates. `v1.0.0` would rank *below* the published `v6.0.4`,
so nobody on `^6.0` would ever be offered the upgrade. `v12.0.0` works mechanically but permanently burns
7 through 11 and falsely implies Laravel-12-only support. The change is breaking, so semver requires a
major bump: 6 → 7.

| Package | Laravel | PHP |
|---|---|---|
| v7.x | 11.x, 12.x | 8.2+ |
| v6.x | 6.x | 7.2+ (frozen) |

A `6.x` branch is cut from the current `master` so the legacy code has a home.

## Breaking changes (v6 → v7)

| # | Change | Migration |
|---|---|---|
| 1 | PHP 7.2 → 8.2 minimum | Upgrade PHP |
| 2 | Laravel 6 → 11/12 | Upgrade framework |
| 3 | Dynamic properties → methods | `$table->format = 'ORC'` becomes `$table->storedAs('ORC')` |
| 4 | `Schema\Builder` → `Schema\HiveSchemaBuilder` | Update imports |
| 5 | Both `HiveGrammar` classes renamed | `HiveQueryGrammar`, `HiveSchemaGrammar` |
| 6 | Config moved to `config/hive.php` | Republish config |
| 7 | Config requires `'driver' => 'hive'` | Included in published file |
| 8 | Insert escaping no longer uses `PDO::quote()` | See below |

Item 8 warrants prominent placement in the changelog, and is the most user-visible change in the release.
Escaping now goes through `HiveValueQuoter` rather than the default connection's PDO driver, so
**generated insert SQL changes for every user**. For most this fixes silently malformed or
driver-dependent output; for anyone who had come to rely on MySQL-style escaping leaking in through the
default connection, the emitted SQL will differ. Applications that insert only non-string values are
unaffected.

## Implementation phases

**Phase 1 — the port.** `composer.json`, `Support/IlluminateVersion`, `Support/HiveTableOptions`,
`Support/HiveValueQuoter`, the rewritten provider/connection/connector/grammars/blueprint/builder, Docker
environment, schema golden capture, full test suite. All risk lives here.

**Phase 2 — quality gates.** Pint config, PHPStan level 6, CI workflow, coverage reporting.

**Phase 3 — documentation and repository standards.** README, CHANGELOG, UPGRADE, `docs/`, license,
community health files, issue and PR templates.

Phases are sequential: the risky work stabilizes before the documentation describes it.

## Out of scope

- Validating against a live Hive cluster (no environment available)
- Binding-based inserts instead of inlined literals. This is the correct long-term answer and PDO_ODBC
  supports it, but it is the one change that cannot be validated without a live Hive ODBC connection.
  `HiveValueQuoter` is scoped as a self-contained unit so a future implementation can replace it cleanly.
- Laravel 10 support (EOL)
- Eloquent-level features such as model factories or migrations beyond DDL compilation
