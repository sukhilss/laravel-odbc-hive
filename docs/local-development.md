# Local Development

This page covers how to build, test, and lint this package on your machine,
and how the Docker setup that everything runs through is actually put
together — not an idealised version of it. There is no PHP or Composer
requirement on the host: every command below runs inside a container via
`docker compose run --rm php <command>`.

## The Docker setup: three services, two behind profiles

`compose.yaml` defines three services:

```yaml
services:
  php:
    build: ./docker/php
    volumes:
      - .:/app
      - composer-cache:/root/.composer/cache
    working_dir: /app

  hive:
    image: apache/hive:4.0.0
    profiles: [hive]
    environment:
      SERVICE_NAME: hiveserver2
    ports:
      - "10000:10000"
      - "10002:10002"

  legacy-capture:
    build: ./docker/legacy-capture
    profiles: [capture]
    volumes:
      - .:/app
    working_dir: /app
```

- **`php`** has no profile, so it is the default and the one you use for
  everything in day-to-day development. It builds from `docker/php/Dockerfile`
  — `php:8.3-cli` with `git`, `unzip`, and the ODBC toolchain
  (`libodbc2`, `odbcinst`, `unixodbc`, `unixodbc-dev`), `pdo` and `pdo_odbc`
  compiled in via `docker-php-ext-configure`/`docker-php-ext-install`, and
  Composer 2 copied in from the official `composer:2` image. It mounts the
  repository at `/app` and keeps a named volume for the Composer cache
  (`composer-cache`) so repeated installs don't re-download packages.
- **`hive`** is gated behind the `hive` profile and only starts on request
  (see below — it does not work out of the box).
- **`legacy-capture`** is gated behind the `capture` profile and backs the
  golden-parity harness (see below).

## The `php` service: the primary development loop

Everything you need day to day goes through `docker compose run --rm php`.
The four commands below were run against this repository to produce the
output shown — nothing here is inferred from reading `composer.json`.

```
$ docker compose run --rm php composer install
Installing dependencies from lock file (including require-dev)
Verifying lock file contents can be installed on current platform.
Nothing to install, update or remove
Generating autoload files
```

```
$ docker compose run --rm php composer test
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.3.30
Configuration: /app/phpunit.xml

...............................................................  63 / 141 ( 44%)
............................................................... 126 / 141 ( 89%)
...............                                                 141 / 141 (100%)

OK (141 tests, 267 assertions)
```

```
$ docker compose run --rm php composer lint
    PASS   .......................................................... 31 files
```

`composer lint` runs `pint --test` and only checks formatting; it does not
rewrite anything. Use `composer fix` (`pint`, no `--test`) to apply Pint's
formatting locally before committing.

```
$ docker compose run --rm php composer analyse
Note: Using configuration file /app/phpstan.neon.
 29/29 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

 [OK] No errors
```

`composer analyse` runs PHPStan (`larastan/larastan`) at the level configured
in `phpstan.neon`, with `--memory-limit=1G`.

If you're changing anything, `composer test && composer lint && composer
analyse` (all through `docker compose run --rm php`) is the full local gate —
it's the same three checks CI runs per matrix cell (see below).

## The `hive` profile: does not work out of the box, and that's not a bug

```
docker compose --profile hive up -d
```

starts `apache/hive:4.0.0` as a real HiveServer2 instance, listening on
`10000` (Thrift) and `10002` (web UI). **This container has no ODBC driver
attached to it, and this package cannot connect to it as-is.** That's
intentional, not an oversight:

- This driver talks to Hive over `PDO_ODBC`, which requires an ODBC driver
  installed on the *client* side (the `php` container, or wherever your
  application runs) that knows how to speak Hive's wire protocol.
- The Hive ODBC driver most commonly used in practice is Cloudera's, and it
  is **proprietary** — Cloudera distributes it under its own license terms,
  not something that can be redistributed via Composer, Packagist, or bundled
  into this repository's Docker image.
- `composer.json` only *suggests* `ext-odbc` for exactly this reason: it's
  not a hard dependency this package can install for you.

To actually connect to a Hive server, you (the operator) must download a
Hive-compatible ODBC driver yourself and drop it into `docker/drivers/`,
which is gitignored (`.gitignore` excludes `docker/drivers/*`, keeping only
`.gitkeep`) precisely so a proprietary driver tarball never ends up in
version control. Wiring that driver into `docker/php/Dockerfile` (or your own
image) and configuring a DSN is outside the scope of this package — see
[`configuration.md`](configuration.md) for the DSN formats the connector
accepts once you have a driver installed.

If you start the `hive` profile without any of that in place, you'll get a
running server that nothing on this repo's `php` container can reach — an
opaque connection error, not a sign the setup is broken. **This repository's
own CI and test suite never start the `hive` profile**, and neither should
you unless you have a driver in hand; the entire test suite runs against an
in-memory SQLite connection instead (see `tests/Support/BlueprintFactory.php`
and `docs/limitations.md`, which states plainly that no Hive server has ever
been used to test this package).

## The `capture` profile and the golden-parity harness

```
docker compose --profile capture run --rm legacy-capture \
  sh -c "sh tools/capture-golden.sh"
```

`tools/capture-golden.sh`:

1. Is **pinned to commit `ea23f65`**, the last commit before this fork's
   Laravel 11/12 port began. The pin is hardcoded (`PINNED_SHA=ea23f65`) so
   the script's output stays reproducible regardless of what's currently
   checked out.
2. Archives just the `src/` tree from that pinned commit into `/tmp/v6`
   (`git archive ea23f65 src | tar -x -C /tmp/v6`).
3. Runs `composer install` inside `docker/legacy-capture` — a separate,
   throwaway Composer project (`docker/legacy-capture/composer.json`)
   requiring `laravel/framework: ^6.0`, with its vendor directory pointed at
   `/tmp/legacy-vendor` and its autoloader mapped onto the archived
   `/tmp/v6/src`. This is why `legacy-capture` needs its own Dockerfile
   (`php:8.0-cli` — old enough for Laravel 6) separate from the `php`
   service's `php:8.3-cli`.
4. Runs `tools/capture-golden.php`, which builds a handful of Blueprint
   fixtures (numeric types, string types, temporal/misc types, and a
   modifiers-are-dropped case) through the **pre-port v6 grammar**, converts
   each to SQL, and writes the result to
   `tests/fixtures/golden-v6-schema.json`.

**What this proves, and what it does not:** `tests/Unit/Schema/GoldenParityTest.php`
runs the same fixture definitions through the current, ported
`HiveSchemaGrammar` and asserts the output is byte-for-byte identical to
what's captured in `golden-v6-schema.json`, except for entries explicitly
registered in `tests/fixtures/intentional-deviations.php` with a reason. This
proves the Laravel 11/12 port introduced **no regression** relative to what
v6 produced for the same migrations. It proves **nothing** about whether that
SQL — v6's or this fork's — is valid HiveQL against a real Hive cluster,
because the harness never talks to Hive; it only compares two grammars'
string output to each other. See `docs/limitations.md` for the broader point
that no Hive server has ever been used to validate this package's SQL.

**`tests/fixtures/golden-v6-schema.json` must never be hand-edited.** It is
machine-generated output from a fixed historical commit, not a fixture you
tune to make a test pass. If `GoldenParityTest` fails, that is telling you
the ported grammar's output changed relative to v6 — the fix is either to
correct the regression in `src/`, or, if the behavior change is deliberate
and reviewed, to register it in `tests/fixtures/intentional-deviations.php`
with a written reason (a real example already there: v6 emitted malformed
`ROW FORMAT` clauses in three distinct ways, and v7 deliberately fixes all
three rather than reproducing them — see that file for the full explanation).
Never touch the golden JSON itself to make a failing comparison pass; that
would defeat the point of the harness. Regenerating it (re-running
`capture-golden.sh`) should only ever reproduce the same file, since the
pinned commit never changes.

## Testing against both Laravel majors

`composer.json` supports `illuminate/database` and `illuminate/support`
`^11.0 || ^12.0`, and CI (`.github/workflows/ci.yml`) tests the full matrix
of PHP 8.2–8.4 × Laravel 11–12 × `prefer-lowest`/`prefer-stable`. To
reproduce a specific cell locally — say, Laravel 11 — pin the constraint,
then update:

```bash
docker compose run --rm php composer require --no-update --no-interaction \
  "illuminate/database:^11.0" "illuminate/support:^11.0"
docker compose run --rm php composer update --prefer-dist --no-interaction --no-blocking
```

**The `--no-blocking` flag is not optional.** Composer 2.10+ refuses by
default to install any package release that carries a known security
advisory, and — as of this writing — **every** `laravel/framework` 11.x
release does. Running the update without `--no-blocking` fails outright, and
the failure does not obviously point at the advisory as the cause; it
surfaces as a wall of `Conclusion: don't install illuminate/database
vX.Y.Z (conflict analysis result)` / `Only one of these can be installed`
dependency-resolution noise, because Composer treats the security-flagged
releases as simply unavailable rather than naming the block directly:

```
Problem 1
    - Root composer.json requires illuminate/database ^11.0 -> satisfiable by illuminate/database[v11.0.0, ..., v11.51.0].
    ...
    - orchestra/testbench v9.17.0 requires laravel/framework ^11.50.0 -> found laravel/framework[v11.50.0, ..., v11.55.0] but these were not loaded, because they are affected by security advisories ("PKSA-m5cs-t1y6-qpcs", ...). ...
    - Conclusion: don't install illuminate/database v11.51.0 (conflict analysis result)
    - Only one of these can be installed: laravel/framework[v12.61.1, v12.62.0, v12.63.0, v12.64.0], illuminate/database[v11.0.0, ..., v11.51.0]. laravel/framework replaces illuminate/database and thus cannot coexist with it.
```

`--no-blocking` tells Composer to install the advisory-flagged release
anyway rather than treating it as nonexistent — appropriate here because this
is a dependency-resolution exercise for testing compatibility, not a
production install. CI passes the same flag for the same reason.

With Laravel 11 installed, confirm the version and run the suite:

```
$ docker compose run --rm php php -r 'require "vendor/autoload.php"; echo \Illuminate\Foundation\Application::VERSION, PHP_EOL;'
11.55.0

$ docker compose run --rm php composer test
OK (141 tests, 268 assertions)
```

The suite passes on Laravel 11 too, though note the assertion count (268)
differs by one from the Laravel 12 baseline (267) despite the same 141 tests
passing on both. This is expected, not a discrepancy to chase down: 
`tests/Unit/Schema/HiveSchemaBuilderTest.php::test_a_registered_resolver_takes_precedence`
branches on `IlluminateVersion::detect()->usesConnectionAwareSchemaApi()`
because `HiveSchemaBuilder::blueprintResolver()`'s callback signature is
version-divergent — the Laravel 12 branch makes 2 assertions inside the
resolver closure, the Laravel 11 branch makes 3, and that is the entire
one-assertion difference. It's a useful example of what "version-conditional
test code" looks like in this codebase (see the guidance below) — the branch
itself is commented in that file with the reasoning.

### Restoring to Laravel 12 afterwards

**Do not just run `composer update` and assume it worked.** A plain
`composer update` can be satisfied entirely by the existing lock file, which
would silently leave your environment on Laravel 11 while composer still
reports success. Discard the pinned constraint, and delete `vendor/` and
`composer.lock` so there's no lock file to be silently satisfied by, before
updating:

```bash
git checkout composer.json && rm -rf vendor composer.lock
docker compose run --rm php composer update --prefer-dist --no-interaction
```

Then confirm the printed version, don't assume it:

```
$ docker compose run --rm php php -r 'require "vendor/autoload.php"; echo \Illuminate\Foundation\Application::VERSION, PHP_EOL;'
12.64.0
```

Followed by a full `composer test` / `composer lint` / `composer analyse` to
confirm you're back on the 141 tests / 267 assertions baseline before
continuing other work.

## When a test fails on only one Laravel major

This package's whole reason for pinning `^11.0 || ^12.0` is that Laravel 11
and 12 don't always behave identically underneath — CI's matrix (PHP ×
Laravel major × `prefer-lowest`/`prefer-stable`) exists specifically to catch
the cases where a test that passes on one combination fails on another.
If you hit that locally:

1. **Reproduce it in isolation first.** Pin the failing major exactly as
   above (`composer require --no-update ... "illuminate/database:^11.0" ...`
   then `composer update --no-blocking`), confirm the printed
   `Application::VERSION`, then run just the failing test:
   `docker compose run --rm php vendor/bin/phpunit --filter=TestName`.
   Confirm it also passes on the other major before concluding anything —
   don't trust a hunch about which version is "the odd one out."
2. **Read the failure against what changed between majors**, not just the
   assertion diff. The usual culprits are signature or default-value changes
   in the `illuminate/database` classes this package extends or grammar
   methods it overrides (`HiveSchemaGrammar`, `HiveQueryGrammar`) — check the
   relevant class in `vendor/illuminate/...` for both majors side by side if
   the cause isn't obvious.
3. **Decide whether the fix is version-conditional or not.** Most fixes
   should work identically on both majors — prefer that. If the two majors
   genuinely require different behavior, that's a real finding to flag in
   the PR description, not something to quietly branch around with a runtime
   `class_exists()`/version check buried in `src/`.
4. **Restore to Laravel 12 before finishing** using the restore sequence
   above (`git checkout composer.json && rm -rf vendor composer.lock &&
   composer update`), and confirm the printed version is `12.64.0`. Leaving
   the repository pinned to Laravel 11 after a debugging session is the
   easiest way to make the *next* person's "unrelated" test failure this
   same problem again.
