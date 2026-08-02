# Security Policy

## Supported Versions

| Version | Laravel | Supported |
|---|---|---|
| v7.x | 11.x, 12.x | :white_check_mark: Yes |
| v6.x | 6.x | :x: End of life — no longer supported |

If you're on v6.x, please upgrade; see [`UPGRADE.md`](UPGRADE.md) for the v6
to v7 migration path. Security reports against v6.x will not receive a
patched release.

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for a suspected security
vulnerability. Instead, report it privately by emailing
**emailtosukhil@gmail.com** with as much detail as you can provide:

- A description of the vulnerability and its potential impact.
- Steps to reproduce it, including a minimal code sample if possible.
- The package version, Laravel version, and PHP version you're using.

You should expect an acknowledgement of your report within a few days. Once
the issue is confirmed, a fix will be prepared and a new release published;
you'll be credited in the release notes unless you ask not to be. Please
give us a reasonable amount of time to address the issue before any public
disclosure.

## A project-specific note on identifiers

This driver validates rather than quotes identifiers. Hive's double quote
delimits a string *literal*, not an identifier, and Hive has no
backtick-style identifier quoting to fall back on — so unlike most database
drivers, table, column, and database names are written into the emitted SQL
**verbatim, not escaped**. `HiveIdentifier` (`src/Support/HiveIdentifier.php`)
— which rejects anything outside `[A-Za-z0-9_]` per dot-separated segment —
is therefore the primary defence against identifier-based SQL injection in
this package, not a secondary safeguard behind quoting.

Because of this, reports touching any of the following should be treated as
security-relevant even if they don't look like a classic injection at first
glance:

- Identifier handling in general (anywhere a table, column, or database name
  reaches the generated SQL).
- `HiveIdentifier` (`src/Support/HiveIdentifier.php`) — the validation
  itself.
- `HiveValueQuoter` (`src/Support/HiveValueQuoter.php`) — value escaping,
  used in place of `PDO::quote()`, which `PDO_ODBC` does not implement.
- `HiveTableWrapper` (`src/Support/HiveTableWrapper.php`) — table-name/prefix
  wrapping.

See [`docs/limitations.md`](docs/limitations.md) for the full detail on how
identifier validation works today and its known edge cases.
