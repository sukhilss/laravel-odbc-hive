# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [7.0.0] - 2026-08-02

A full rewrite for Laravel 11/12 and PHP 8.2+, ported from the Laravel 6 /
PHP 7.2 codebase. If you are upgrading from v6, read [`UPGRADE.md`](UPGRADE.md)
first — several changes are not source-compatible.

Entries below are written against the **last released** v6, `v6.0.4` (commit
`0a69cf8`) — what a Packagist user on `^6.0` actually has. This port is based
on `ea23f65`, two commits *after* that tag, which was never released; where a
change only applies relative to those untagged commits, the entry says so.

### Added

- Identifier validation (`Sukhil\Database\Hive\Support\HiveIdentifier`) on
  the insert, query, and schema paths: table, column, and alias identifiers
  are now checked against `[A-Za-z0-9_]` per dot-separated segment and
  rejected with `InvalidArgumentException` if they don't match. This closes
  a real exposure in v6: neither grammar quoted table identifiers, and the
  schema grammar's column-identifier escaping had no surrounding delimiter
  to escape into. v6's query-side column identifiers were already quoted and
  escaped by the inherited base Illuminate grammar. See `UPGRADE.md` item 7
  for the full history.
- Support for schema-qualified table names (`analytics.events`) and dotted
  table prefixes (`'analytics.'`) on both the query and schema grammars,
  including correct handling of aliased and joined tables under a dotted
  prefix.
- A test suite (`tests/Unit`, `tests/Feature`), including a golden-parity
  harness that pins the schema grammar's DDL output against what the
  pre-port Laravel 6 code produced for the same migrations. The harness is
  pinned to commit `ea23f65` (untagged master), not to `v6.0.4`.
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
  `HiveTableOptions` value object. Note that `charset` is a *new* option,
  not a migrated one: `v6.0.4`'s grammar read only `format`, `delimiter`,
  and `location` — the clause that consumes `charset` was added after the
  tag. `charset` is also the one option whose old spelling
  (`$table->charset = ...`) raises no PHP deprecation notice under v7,
  because Laravel's base `Blueprint` declares `public $charset;`; see
  `UPGRADE.md` item 5.
- **Inserts and raw statements no longer use bound parameters.** `v6.0.4`
  compiled inserts with `?` placeholders (`parameterize()`) and executed
  both inserts and `statement()` through `PDO::prepare()` / `bindValues()` /
  `execute()`. v7 inlines insert values directly into the generated SQL,
  escaped by a new `Sukhil\Database\Hive\Support\HiveValueQuoter` applying
  Hive's own C-style escaping rules, and `HiveConnection::statement()` runs
  `PDO::exec()` without applying `$bindings` at all — the Hive ODBC driver
  does not support parameter binding on the statement path this package
  uses. Consequences: the SQL generated for every insert differs from
  `v6.0.4`'s, and a `$bindings` array passed to `DB::statement()` is
  discarded rather than substituted. See `UPGRADE.md` item 8 and "Inserts
  are inlined literals, not bound parameters" in `docs/limitations.md`.
  (The untagged commits after `v6.0.4` had already made this change; it is
  listed here because it is new relative to the last release.)

### Fixed

- **Insert escaping no longer uses `PDO::quote()`.** This is a fix relative
  to the untagged master commits after `v6.0.4` (`95d33bf`, `ea23f65`) —
  this port's base — not to any released v6. Those commits escaped string
  insert values with `\DB::getPdo()->quote()`, which is wrong twice over:
  it reaches the application's *default* connection rather than the Hive
  connection being written to, and PDO_ODBC does not implement `quote()` at
  all — it returns `false`, which was concatenated straight into the
  generated SQL. `v6.0.4` itself bound its insert values and was not
  affected. v7 escapes through `HiveValueQuoter` instead.
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
- Malformed table-options DDL, in three ways — but only one of the three
  affected a release. The `ROW FORMAT SERDE` clause driven by
  `$blueprint->charset` was **added after `v6.0.4`**, in the untagged
  commits `95d33bf`/`ea23f65`; `v6.0.4` read no `charset` at all and emitted
  no SerDe clause. So the missing space before `ROW FORMAT`, and both the
  SerDe and `DELIMITED` row-format clauses being emitted at once when
  `charset()` and `delimiter()` were both set, are fixes relative to
  untagged master only. The third — `ROW FORMAT DELIMITED` emitted *after*
  `STORED AS`, where HiveQL wants `ROW FORMAT`, then `STORED AS`, then
  `LOCATION` — was present in `v6.0.4` and is a fix for released v6 too.
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
