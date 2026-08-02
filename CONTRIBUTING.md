# Contributing

Bug reports and pull requests are welcome.

## Setup

Everything runs through Docker — there is no PHP or Composer requirement on
your host. See [`docs/local-development.md`](docs/local-development.md) for
the full setup: what the `php` service is, how to run the test suite against
both Laravel majors, and how the (optional, not-required-for-normal-work)
`hive` and `capture` profiles work. The short version, once you've cloned the
repository:

```bash
docker compose run --rm php composer install
```

## The three gates

A pull request must pass all three of these before it can be merged, each
run through `docker compose run --rm php`:

```bash
docker compose run --rm php composer test      # PHPUnit — expect 141 tests, 267 assertions
docker compose run --rm php composer lint      # Pint, --test mode: checks formatting, does not rewrite
docker compose run --rm php composer analyse   # PHPStan (larastan) at level 6
```

If `composer lint` reports formatting problems, run `docker compose run --rm
php composer fix` (Pint without `--test`) to apply them locally, then re-run
`composer lint` to confirm.

`composer analyse` runs PHPStan at **level 6 with no baseline file**. That's
deliberate: a baseline would let existing violations sit silently and hide
new ones introduced alongside them. There is nothing to grandfather in — a
new violation must be fixed outright, or suppressed inline with a
`@phpstan-ignore` comment carrying a written reason, not silently absorbed
into a baseline.

## Two rules the code alone won't tell you

**1. Version-dependent code is confined to exactly four sites.** This
package supports Laravel 11 and 12 (`illuminate/database` / `illuminate/support`
`^11.0 || ^12.0`), which don't always behave identically underneath. Rather
than letting version checks spread through the codebase, every place that
needs to branch on the installed Laravel version is deliberately
concentrated into four methods, and only these four:

- `HiveConnection::configureGrammar()` (`src/HiveConnection.php`)
- `HiveSchemaBuilder::createBlueprint()` (`src/Schema/HiveSchemaBuilder.php`)
- `HiveQueryGrammar::__construct()` (`src/Query/Grammars/HiveQueryGrammar.php`)
- `HiveSchemaGrammar::__construct()` (`src/Schema/Grammars/HiveSchemaGrammar.php`)

If you find yourself writing `class_exists()`, checking
`Application::VERSION`, or calling `IlluminateVersion::detect()` anywhere
else in `src/`, that's a sign the change belongs in one of these four
methods instead, not a new site. In particular: **new Hive type mappings
belong in `HiveSchemaGrammar`**, as ordinary (non-version-conditional) column
type compilers — not behind a version branch. Type mapping is a Hive
concern, not a Laravel-version concern.

**2. `PDO::quote()` must never be used.** `PDO_ODBC`, the driver this
package connects through, does not implement it — it returns `false` rather
than an escaped string, silently. All value escaping goes through
`HiveValueQuoter` (`src/Support/HiveValueQuoter.php`), and all identifier
validation goes through `HiveIdentifier` (`src/Support/HiveIdentifier.php`).
If a change you're making needs to put a value or identifier into a raw SQL
string, use one of those two — never `$pdo->quote()` or `$connection->quote()`.

## Pull requests

Please include:

- What the change does and why, not just what changed.
- Confirmation that all three gates above pass.
- New or updated tests covering the change (see
  [`docs/local-development.md`](docs/local-development.md) for how the test
  suite is organised, including the golden-parity harness under
  `tests/Unit/Schema/GoldenParityTest.php`).

If you're touching anything version-conditional, read the "Two rules" section
above first — a PR that adds a fifth version-branching site, or a sixth,
outside the four listed methods will be asked to consolidate before merge.
