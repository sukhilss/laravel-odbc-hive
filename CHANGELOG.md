# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [7.0.0]

A full rewrite for Laravel 11/12 and PHP 8.2+, ported from the Laravel 6 /
PHP 7.2 codebase. If you are upgrading from v6, read [`UPGRADE.md`](UPGRADE.md)
first — several changes are not source-compatible.

### Added

- Identifier validation (`Sukhil\Database\Hive\Support\HiveIdentifier`) on
  the insert, query, and schema paths: table, column, and alias identifiers
  are now checked against `[A-Za-z0-9_]` per dot-separated segment and
  rejected with `InvalidArgumentException` if they don't match. This closes
  a real exposure in v6: both grammars' `wrapTable()` emitted table
  identifiers verbatim, and the schema grammar's column-identifier escaping
  had no surrounding delimiter to escape into. v6's query-side column
  identifiers were already quoted and escaped by the inherited base
  Illuminate grammar. See `UPGRADE.md` item 7 for the full history.
- Support for schema-qualified table names (`analytics.events`) and dotted
  table prefixes (`'analytics.'`) on both the query and schema grammars,
  including correct handling of aliased and joined tables under a dotted
  prefix.
- A test suite (`tests/Unit`, `tests/Feature`), including a golden-parity
  harness that pins the schema grammar's DDL output against what the
  pre-port Laravel 6 code produced for the same migrations.
- PHPStan (Larastan) at level 6, with no baseline.
- Laravel Pint, configured with the Laravel preset.
- GitHub Actions CI: a Laravel 11/12 × PHP version matrix running tests,
  static analysis, linting, and a coverage artifact.
- Documentation: `docs/configuration.md`, `docs/schema-builder.md`,
  `docs/limitations.md`, and `docs/local-development.md`.
- MIT licence text (`LICENSE.md`).

### Changed

- **Minimum PHP raised from 7.2 to 8.2.**
- **Minimum Laravel raised from 6.x to 11.x/12.x** (`illuminate/database`
  `^11.0 || ^12.0`).
- Config file moved from `src/config/hive.php` (package-internal) to
  `config/hive.php`, and the publish tag renamed from `config` to
  `hive-config`. Publishing with `--tag=config` now matches nothing.
- `Schema\Builder` renamed to `Schema\HiveSchemaBuilder`.
- Both `HiveGrammar` classes renamed and split by concern:
  `Query\Grammars\HiveGrammar` → `Query\Grammars\HiveQueryGrammar`,
  `Schema\Grammars\HiveGrammar` → `Schema\Grammars\HiveSchemaGrammar`.
- `HiveBlueprint`'s Hive-specific table options (`format`, `location`,
  `delimiter`, `charset`) moved from dynamic properties (relied on dynamic
  property creation, deprecated since PHP 8.2) to typed fluent methods
  (`storedAs()`, `location()`, `delimiter()`, `charset()`) backed by a new
  `HiveTableOptions` value object.

### Fixed

- **Insert escaping no longer uses `PDO::quote()`.** PDO_ODBC does not
  implement `quote()` — it returns `false`, which v6 concatenated straight
  into the generated SQL. Inserts are now escaped through a new
  `HiveValueQuoter`, applying Hive's own C-style escaping rules.
- **Insert values no longer escape through the default connection's PDO.**
  v6 escaped string insert values via `\DB::getPdo()->quote()` — the
  application's *default* connection, not the Hive connection actually
  being written to. See `UPGRADE.md` for the full explanation; this is the
  highest-impact fix in this release.
- Batch inserts with reordered keys no longer pair values with the wrong
  columns: rows are now aligned to the first row's column order rather than
  paired positionally. A row whose key set doesn't match the first row's
  (missing or extra columns) now throws `InvalidArgumentException` naming
  the row and the mismatched columns, instead of silently writing `NULL`
  or dropping a value.
- `HiveConnection` no longer rejects the lazy-connection closure Laravel
  passes: the old typed `PDO $pdo` constructor fatally mismatched the
  parent's `\PDO|(\Closure(): \PDO)` signature. `HiveConnection` no longer
  overrides the constructor at all.
- The published config now carries `'driver' => 'hive'`. In v6 the
  published default config omitted this key, so a connection built purely
  from the published file could never resolve, even with a correct DSN.
- Malformed table-options DDL: a missing space before `ROW FORMAT`, both
  the SerDe and `DELIMITED` row-format clauses being emitted at once when
  both `charset()` and `delimiter()` were set, and wrong clause ordering
  relative to `STORED AS`.
- `statement()` no longer reports a successful zero-row DDL statement
  (e.g. `CREATE TABLE`) as a failure. `PDO::exec()` returns `int(0)`, not
  `false`, for a successful statement affecting no rows; the previous
  `(bool)` cast turned that `0` into `false`.
- Table identifiers, and schema-side (DDL) column identifiers, are now
  validated rather than emitted with no effective escaping — see "Added"
  above. Listed again here because, alongside the injection defence, this
  is also a correctness fix: an unrecognised identifier now throws instead
  of silently producing malformed SQL.
- `HiveConnector::connect()` now throws `InvalidArgumentException` (`Hive
  DSN is not configured. Set the "dsn" key on the connection (or the
  HIVE_DSN environment variable) to an ODBC DSN.`) when the `dsn` config
  key is missing or empty, rather than passing an empty/missing DSN through
  to PDO and surfacing an opaque driver-level error.

### Removed

- `phpcs.xml` (superseded by Pint).
- `HiveServiceProvider::provides()`. It returned an empty array in v6
  (dead code), and later — briefly, in this port — a non-empty array that
  would have made the provider deferrable, which breaks it in two ways:
  `register()`'s side effects would not yet be in place when a connection
  is built, and `boot()` would never run to merge package connections. The
  method is now absent entirely.

---

v6.0.1 through v6.0.4 shipped in 2019 without a changelog. Those releases
predate this file; no entries are reconstructed for them here.

[Unreleased]: https://github.com/sukhilss/laravel-odbc-hive/compare/v7.0.0...HEAD
[7.0.0]: https://github.com/sukhilss/laravel-odbc-hive/compare/v6.0.4...v7.0.0
