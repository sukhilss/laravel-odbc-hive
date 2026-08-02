# Phase 1: Laravel 11/12 Port Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Port `sukhilss/laravel-odbc-hive` from Laravel 6 / PHP 7.2 to Laravel 11+12 / PHP 8.2+, fixing five latent defects, under a test suite that runs entirely in Docker.

**Architecture:** All Hive knowledge lives in version-agnostic classes. The Laravel 11↔12 API divergence is absorbed at two construction sites (`HiveConnection` for grammars, `HiveSchemaBuilder` for blueprints), both delegating to a single `IlluminateVersion` capability probe. Insert escaping moves off `PDO::quote()` — which PDO_ODBC does not implement — onto a self-contained `HiveValueQuoter`.

**Tech Stack:** PHP 8.2+, illuminate/database ^11|^12, PHPUnit ^11, Orchestra Testbench ^9|^10, Docker Compose.

**Spec:** `docs/superpowers/specs/2026-07-25-laravel-11-12-modernization-design.md`

## Global Constraints

- Target release is **v7.0.0**. Package major no longer tracks Laravel major.
- PHP floor is **8.2**. Laravel support is **^11.0 || ^12.0**. Laravel 10 and below are out of scope.
- Every PHP file in `src/` and `tests/` begins with `declare(strict_types=1);`.
- Namespace root is `Sukhil\Database\Hive\` → `src/`. Tests are `Sukhil\Database\Hive\Tests\` → `tests/`.
- **No local PHP toolchain exists.** Every PHP and Composer command runs through Docker: `docker compose run --rm php <command>`.
- `PDO::quote()` must never be called. PDO_ODBC does not implement it and returns `false`.
- Version-dependent code is permitted in exactly four places, and nowhere else:
  - `HiveConnection` and `HiveSchemaBuilder` — branch on `IlluminateVersion::usesConnectionAwareSchemaApi()`
  - `HiveQueryGrammar::__construct` and `HiveSchemaGrammar::__construct` — branch on `method_exists(parent::class, '__construct')`, because a grammar cannot ask `IlluminateVersion` about its own parent before the parent is initialised

  All method *bodies* must be version-agnostic. Branching belongs in construction only.
- Golden capture pins to commit **`ea23f65`**, not to `HEAD` or to a tag.
- Do not commit `composer.lock` — this is a library.

---

## File Structure

**Created:**

| Path | Responsibility |
|---|---|
| `compose.yaml` | Service definitions: `php`, `hive` (profile), `legacy-capture` (profile) |
| `docker/php/Dockerfile` | PHP 8.3 CLI + unixODBC + pdo_odbc + Composer |
| `docker/legacy-capture/Dockerfile` | PHP 8.0 CLI + Composer, for running Laravel 6 |
| `docker/legacy-capture/composer.json` | Pins `laravel/framework:^6.0`, independent of root |
| `phpunit.xml` | Test suites, coverage config |
| `config/hive.php` | Published config, replaces `src/config/hive.php` |
| `src/Support/IlluminateVersion.php` | Capability probe for the schema API shape |
| `src/Support/HiveValueQuoter.php` | Hive C-style literal escaping |
| `src/Support/HiveTableOptions.php` | Typed table options (ORC, location, delimiter, charset) |
| `src/Schema/HiveBlueprint.php` | Adds `varChar()` and table-option methods |
| `src/Schema/Grammars/HiveSchemaGrammar.php` | DDL compilation, all Hive type mappings |
| `src/Schema/HiveSchemaBuilder.php` | Blueprint factory; **version branch** |
| `src/Query/Grammars/HiveQueryGrammar.php` | Insert compilation, table wrapping |
| `tools/capture-golden.php` | One-shot v6 DDL capture harness |
| `tests/TestCase.php` | Testbench base class |
| `tests/Unit/**`, `tests/Feature/**` | Test suite |

**Modified:** `composer.json`, `.gitignore`, `src/HiveConnection.php`, `src/HiveServiceProvider.php`, `src/Connectors/HiveConnector.php`, `src/Query/Processors/HiveProcessor.php`

**Deleted:** `phpcs.xml`, `src/config/hive.php`, `src/Schema/Builder.php`, `src/Schema/Grammars/HiveGrammar.php`, `src/Query/Grammars/HiveGrammar.php`

---

### Task 1: Docker environment and Composer manifest

**Files:**
- Create: `docker/php/Dockerfile`, `compose.yaml`, `phpunit.xml`, `tests/TestCase.php`, `tests/Unit/SmokeTest.php`
- Modify: `composer.json`, `.gitignore`
- Delete: `phpcs.xml`

**Interfaces:**
- Consumes: nothing
- Produces: `docker compose run --rm php composer test` as the universal test command; `Sukhil\Database\Hive\Tests\TestCase` as the Testbench base class for all later feature tests.

- [ ] **Step 1: Create the PHP Dockerfile**

`docker/php/Dockerfile`:
```dockerfile
FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libodbc2 odbcinst unixodbc unixodbc-dev \
    && docker-php-ext-configure pdo_odbc --with-pdo-odbc=unixODBC,/usr \
    && docker-php-ext-install pdo pdo_odbc \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
```

Two details are load-bearing and were verified during implementation:

- **`libodbc2`, not `libodbc1`** — Debian trixie (the `php:8.3-cli` base) renamed the package.
- **`docker-php-ext-configure pdo_odbc --with-pdo-odbc=unixODBC,/usr`** — the official PHP images require this on any Debian base; without it `pdo_odbc` will not compile.

Confirm with `docker compose run --rm php php -m | grep -i odbc`, which must list `PDO_ODBC`. `pdo_sqlite` must also be present — later tasks build in-memory SQLite PDOs for grammar tests.

- [ ] **Step 2: Create compose.yaml**

`compose.yaml`:
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

volumes:
  composer-cache:
```

- [ ] **Step 3: Replace composer.json**

```json
{
    "name": "sukhilss/laravel-odbc-hive",
    "description": "Apache Hive database driver for Laravel, over ODBC/PDO.",
    "keywords": ["laravel", "hive", "database", "pdo", "odbc", "hadoop"],
    "license": "MIT",
    "authors": [
        {
            "name": "Sukhil S S",
            "role": "Developer"
        }
    ],
    "require": {
        "php": "^8.2",
        "ext-pdo": "*",
        "illuminate/database": "^11.0 || ^12.0",
        "illuminate/support": "^11.0 || ^12.0"
    },
    "require-dev": {
        "larastan/larastan": "^3.0",
        "laravel/pint": "^1.18",
        "orchestra/testbench": "^9.0 || ^10.0",
        "phpstan/phpstan": "^2.0",
        "phpunit/phpunit": "^10.5 || ^11.0"
    },
    "suggest": {
        "ext-odbc": "Required to connect to a real Hive server over ODBC."
    },
    "autoload": {
        "psr-4": {
            "Sukhil\\Database\\Hive\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Sukhil\\Database\\Hive\\Tests\\": "tests/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Sukhil\\Database\\Hive\\HiveServiceProvider"
            ]
        }
    },
    "config": {
        "sort-packages": true
    },
    "minimum-stability": "stable",
    "prefer-stable": true,
    "scripts": {
        "test": "phpunit",
        "lint": "pint --test",
        "fix": "pint",
        "analyse": "phpstan analyse"
    }
}
```

Note `ext-odbc` is *suggested*, not required, so CI and the test suite run without it.

- [ ] **Step 4: Create phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
         failOnWarning="true"
         failOnRisky="true"
         failOnDeprecation="true"
         failOnNotice="true"
         failOnPhpunitDeprecation="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 5: Create the Testbench base class**

`tests/TestCase.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sukhil\Database\Hive\HiveServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [HiveServiceProvider::class];
    }
}
```

Also create `tests/Feature/.gitkeep`. `phpunit.xml` declares a `Feature` suite pointing at `tests/Feature`, and git does not track empty directories — without a tracked file there, `composer test` fails on a fresh clone.

- [ ] **Step 6: Create a smoke test**

`tests/Unit/SmokeTest.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function test_the_test_harness_runs(): void
    {
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 7: Update .gitignore**

```
.idea
.vscode
/vendor
composer.lock
.phpunit.cache
.phpunit.result.cache
/build
docker/drivers/*
!docker/drivers/.gitkeep
```

- [ ] **Step 8: Delete phpcs.xml**

```bash
git rm phpcs.xml
```

- [ ] **Step 9: Build the image and install dependencies**

Run: `docker compose build php && docker compose run --rm php composer install`
Expected: Composer resolves illuminate/database 11 or 12 and installs without conflict.

- [ ] **Step 10: Run the smoke test**

Run: `docker compose run --rm php composer test`
Expected: PASS, 1 test, 1 assertion.

- [ ] **Step 11: Commit**

```bash
git add composer.json phpunit.xml compose.yaml docker/ tests/ .gitignore
git rm --cached phpcs.xml 2>/dev/null || true
git commit -m "build: add Docker toolchain and Laravel 11/12 composer manifest"
```

---

### Task 2: Capture v6 golden DDL output

Runs against pinned commit `ea23f65`, extracted to a temp directory, so it stays reproducible after `src/` is rewritten.

**Files:**
- Create: `docker/legacy-capture/Dockerfile`, `docker/legacy-capture/composer.json`, `tools/capture-golden.php`, `tests/fixtures/golden-v6-schema.json`, `tests/fixtures/intentional-deviations.php`

**Interfaces:**
- Consumes: nothing
- Produces: `tests/fixtures/golden-v6-schema.json`, a JSON object mapping fixture name → array of SQL strings. Task 12's `GoldenParityTest` asserts against it. `intentional-deviations.php` returns `array<string, string>` mapping fixture name → reason the output legitimately differs.

- [ ] **Step 1: Create the legacy capture Dockerfile**

`docker/legacy-capture/Dockerfile`:
```dockerfile
FROM php:8.0-cli

RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
```

- [ ] **Step 2: Create the isolated Laravel 6 manifest**

`docker/legacy-capture/composer.json` — deliberately separate from the root manifest so Laravel 6 and Laravel 11/12 never resolve together:
```json
{
    "require": {
        "laravel/framework": "^6.0"
    },
    "autoload": {
        "psr-4": {
            "Sukhil\\Database\\Hive\\": "/tmp/v6/src/"
        }
    },
    "config": {
        "vendor-dir": "/tmp/legacy-vendor"
    }
}
```

- [ ] **Step 3: Write the capture harness**

`tools/capture-golden.php`:
```php
<?php

/**
 * Captures DDL output from the pre-port grammar so the ported implementation
 * can be checked for regressions.
 *
 * Pinned to commit ea23f65. Run via:
 *   docker compose --profile capture run --rm legacy-capture \
 *     sh -c "sh tools/capture-golden.sh"
 */

require '/tmp/legacy-vendor/autoload.php';

use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\Schema\Blueprint;
use Sukhil\Database\Hive\Schema\Grammars\HiveGrammar;

$connection = new SQLiteConnection(new PDO('sqlite::memory:'));
$grammar = new HiveGrammar();

$fixtures = [
    'numeric_types' => function (Blueprint $table): void {
        $table->integer('integer_field');
        $table->bigInteger('big_integer');
        $table->smallInteger('small_integer');
        $table->tinyInteger('tinyinteger_field');
        $table->float('float_field');
        $table->double('double_field');
        $table->decimal('decimal_field');
    },
    'string_types' => function (Blueprint $table): void {
        $table->string('string_field');
        $table->char('char_field');
        $table->text('text_field');
        $table->mediumText('medium_text_field');
        $table->longText('long_text_field');
    },
    'temporal_and_misc_types' => function (Blueprint $table): void {
        $table->timestamp('timestamp_field');
        $table->date('date_field');
        $table->dateTime('datetime_field');
        $table->boolean('boolean_field');
        $table->binary('binary_field');
    },
    'modifiers_are_dropped' => function (Blueprint $table): void {
        $table->string('nullable_field')->nullable();
        $table->integer('default_field')->default(7);
        $table->integer('unsigned_field')->unsigned();
    },
];

$golden = [];

foreach ($fixtures as $name => $definition) {
    $blueprint = new Blueprint('sample_table', $definition);
    $blueprint->create();
    $golden[$name] = $blueprint->toSql($connection, $grammar);
}

// Table options were dynamic properties in v6.
$optioned = new Blueprint('optioned_table', function (Blueprint $table): void {
    $table->string('name');
});
$optioned->create();
$optioned->charset = 'UTF-8';
$optioned->format = 'ORC';
$optioned->delimiter = ',';
$optioned->location = '/warehouse/optioned';
$golden['table_options'] = $optioned->toSql($connection, $grammar);

file_put_contents(
    '/app/tests/fixtures/golden-v6-schema.json',
    json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

echo "Captured " . count($golden) . " fixtures.\n";
```

- [ ] **Step 4: Write the capture shell wrapper**

`tools/capture-golden.sh`:
```bash
#!/bin/sh
set -eu

# Pinned to the pre-port commit so this stays reproducible forever.
PINNED_SHA=ea23f65

rm -rf /tmp/v6
mkdir -p /tmp/v6
git -C /app archive "$PINNED_SHA" src | tar -x -C /tmp/v6

cd /app/docker/legacy-capture
composer install --no-interaction --quiet

php /app/tools/capture-golden.php
```

- [ ] **Step 5: Run the capture**

Run:
```bash
mkdir -p tests/fixtures
docker compose --profile capture build legacy-capture
docker compose --profile capture run --rm legacy-capture sh /app/tools/capture-golden.sh
```
Expected: `Captured 5 fixtures.` and `tests/fixtures/golden-v6-schema.json` exists.

- [ ] **Step 6: Inspect the captured output**

Run: `cat tests/fixtures/golden-v6-schema.json`
Expected: each value is an array containing one `create table sample_table (...)` string. Confirm `modifiers_are_dropped` shows no `null`, `default`, or `unsigned` text — that proves the empty-`$modifiers` behavior was captured faithfully rather than assumed.

**If the file is empty or the command errors, stop and report.** A silently empty golden file would make Task 12 vacuously pass, which is worse than having no harness at all.

- [ ] **Step 7: Create the deviations register**

`tests/fixtures/intentional-deviations.php`:
```php
<?php

declare(strict_types=1);

/**
 * Fixtures whose ported output legitimately differs from v6, with the reason.
 *
 * GoldenParityTest skips these and prints the reason. Every entry is a
 * deliberate, reviewed behavior change — not a tolerated regression.
 *
 * @return array<string, string>
 */
return [
    'table_options' => 'v6 set charset/format/delimiter/location as dynamic properties, '
        . 'deprecated in PHP 8.2. Replaced by HiveTableOptions and explicit builder methods. '
        . 'Emitted SQL clauses are unchanged; only the API to set them differs.',
];
```

- [ ] **Step 8: Commit**

```bash
git add docker/legacy-capture tools/ tests/fixtures/
git commit -m "test: capture v6 golden DDL output from pinned commit ea23f65"
```

---

### Task 3: IlluminateVersion capability probe

**Files:**
- Create: `src/Support/IlluminateVersion.php`, `tests/Unit/Support/IlluminateVersionTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `IlluminateVersion::__construct(bool $connectionAwareSchemaApi)`
  - `IlluminateVersion::detect(): self`
  - `IlluminateVersion::usesConnectionAwareSchemaApi(): bool` — `true` on Laravel 12+, `false` on Laravel 11
  - Used by `HiveConnection` (Task 10) and `HiveSchemaBuilder` (Task 8).

**Design note:** This probes the actual `Blueprint::__construct` signature by reflection rather than comparing version strings. A version string tells you what was released; the signature tells you what you are actually running.

**Testing note:** only one Laravel major is installed at a time, so a test that reflects on the installed `Blueprint` must recompute this class's own formula to know what to expect — which makes it tautological and blind to a hardcoded-constant regression. That is why the logic is split into `forClass()`: the real coverage drives it with fixture classes of known signature (`Connection`-typed first parameter, untyped first parameter, union-typed first parameter, zero parameters) and asserts the boolean literally.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Support/IlluminateVersionTest.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Support;

use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Sukhil\Database\Hive\Support\IlluminateVersion;

final class IlluminateVersionTest extends TestCase
{
    public function test_it_reports_connection_aware_api_when_constructed_true(): void
    {
        $version = new IlluminateVersion(true);

        $this->assertTrue($version->usesConnectionAwareSchemaApi());
    }

    public function test_it_reports_legacy_api_when_constructed_false(): void
    {
        $version = new IlluminateVersion(false);

        $this->assertFalse($version->usesConnectionAwareSchemaApi());
    }

    public function test_detect_matches_the_installed_blueprint_signature(): void
    {
        $firstParameter = (new \ReflectionMethod(Blueprint::class, '__construct'))
            ->getParameters()[0];
        $type = $firstParameter->getType();

        $expected = $type instanceof \ReflectionNamedType
            && is_a($type->getName(), \Illuminate\Database\Connection::class, true);

        $this->assertSame($expected, IlluminateVersion::detect()->usesConnectionAwareSchemaApi());
    }

    // The real coverage: fixture classes of known signature, asserted
    // literally. These fail if forClass() is ever replaced by a constant,
    // regardless of which Laravel major happens to be installed.

    public function test_a_connection_typed_first_parameter_means_laravel_12(): void
    {
        $this->assertTrue(
            IlluminateVersion::forClass(ConnectionFirstStub::class)->usesConnectionAwareSchemaApi()
        );
    }

    public function test_an_untyped_first_parameter_means_laravel_11(): void
    {
        $this->assertFalse(
            IlluminateVersion::forClass(UntypedFirstStub::class)->usesConnectionAwareSchemaApi()
        );
    }

    public function test_a_union_typed_first_parameter_is_not_connection_aware(): void
    {
        $this->assertFalse(
            IlluminateVersion::forClass(UnionFirstStub::class)->usesConnectionAwareSchemaApi()
        );
    }

    public function test_a_zero_parameter_constructor_is_not_connection_aware(): void
    {
        // Must not emit an "Undefined array key 0" warning.
        $this->assertFalse(
            IlluminateVersion::forClass(NoParamsStub::class)->usesConnectionAwareSchemaApi()
        );
    }
}

final class ConnectionFirstStub
{
    public function __construct(Connection $connection, string $table)
    {
    }
}

final class UntypedFirstStub
{
    public function __construct($table, ?string $prefix = null)
    {
    }
}

final class UnionFirstStub
{
    public function __construct(Connection|string $first)
    {
    }
}

final class NoParamsStub
{
    public function __construct()
    {
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Support/IlluminateVersionTest.php`
Expected: FAIL — `Class "Sukhil\Database\Hive\Support\IlluminateVersion" not found`.

- [ ] **Step 3: Write the implementation**

`src/Support/IlluminateVersion.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Detects which shape of the Illuminate schema API is installed.
 *
 * Laravel 12 changed Blueprint::__construct to take a Connection as its first
 * argument, and dropped the Connection argument from Grammar::compileCreate.
 * Laravel 11 retains the older signatures. This class is the single place in
 * the package that cares about the difference.
 */
final class IlluminateVersion
{
    public function __construct(
        private readonly bool $connectionAwareSchemaApi,
    ) {
    }

    /**
     * Probe the installed framework by inspecting Blueprint's constructor.
     */
    public static function detect(): self
    {
        return self::forClass(Blueprint::class);
    }

    /**
     * Probe an arbitrary class's constructor.
     *
     * Split out from detect() so tests can drive it with fixture classes of
     * known signature. Asserting against the installed Blueprint alone is
     * tautological — the test would have to recompute this same expression to
     * know what to expect, and would still pass if this method were replaced
     * by a hardcoded constant.
     *
     * @param  class-string  $class
     */
    public static function forClass(string $class): self
    {
        $parameters = (new ReflectionMethod($class, '__construct'))->getParameters();
        $type = ($parameters[0] ?? null)?->getType();

        return new self(
            $type instanceof ReflectionNamedType
                && is_a($type->getName(), Connection::class, true),
        );
    }

    /**
     * True on Laravel 12+, where Blueprint and the grammars receive a Connection.
     */
    public function usesConnectionAwareSchemaApi(): bool
    {
        return $this->connectionAwareSchemaApi;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Support/IlluminateVersionTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Support/IlluminateVersion.php tests/Unit/Support/IlluminateVersionTest.php
git commit -m "feat: add IlluminateVersion schema API capability probe"
```

---

### Task 4: HiveValueQuoter

The most consequential class in the port. PDO_ODBC does not implement `PDO::quote()`; it returns `false`, which `implode()` renders as an empty string, producing `values (, 30)`.

**Files:**
- Create: `src/Support/HiveValueQuoter.php`, `tests/Unit/Support/HiveValueQuoterTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `HiveValueQuoter::quoteString(string $value): string` — escapes and wraps in single quotes
  - `HiveValueQuoter::literal(mixed $value): string` — dispatches by type; used by `HiveQueryGrammar` (Task 9)

- [ ] **Step 1: Write the failing test**

`tests/Unit/Support/HiveValueQuoterTest.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Sukhil\Database\Hive\Support\HiveValueQuoter;

final class HiveValueQuoterTest extends TestCase
{
    private HiveValueQuoter $quoter;

    protected function setUp(): void
    {
        $this->quoter = new HiveValueQuoter();
    }

    public function test_it_wraps_a_plain_string_in_single_quotes(): void
    {
        $this->assertSame("'Alice'", $this->quoter->quoteString('Alice'));
    }

    public function test_it_escapes_single_quotes(): void
    {
        $this->assertSame("'O\\'Brien'", $this->quoter->quoteString("O'Brien"));
    }

    public function test_it_escapes_backslashes(): void
    {
        $this->assertSame("'a\\\\b'", $this->quoter->quoteString('a\\b'));
    }

    public function test_it_does_not_double_escape_an_escaped_quote(): void
    {
        // Input is a literal backslash followed by a quote. The backslash must
        // become two backslashes and the quote must gain its own backslash,
        // rather than the replacement being re-processed.
        $this->assertSame("'\\\\\\''", $this->quoter->quoteString("\\'"));
    }

    public function test_it_escapes_control_characters(): void
    {
        $this->assertSame("'a\\nb'", $this->quoter->quoteString("a\nb"));
        $this->assertSame("'a\\rb'", $this->quoter->quoteString("a\rb"));
        $this->assertSame("'a\\tb'", $this->quoter->quoteString("a\tb"));
    }

    public function test_it_handles_an_empty_string(): void
    {
        $this->assertSame("''", $this->quoter->quoteString(''));
    }

    public function test_literal_renders_null_as_the_null_keyword(): void
    {
        $this->assertSame('NULL', $this->quoter->literal(null));
    }

    public function test_literal_renders_booleans_as_hive_keywords(): void
    {
        $this->assertSame('true', $this->quoter->literal(true));
        $this->assertSame('false', $this->quoter->literal(false));
    }

    public function test_literal_renders_numbers_without_quotes(): void
    {
        $this->assertSame('42', $this->quoter->literal(42));
        $this->assertSame('1.5', $this->quoter->literal(1.5));
    }

    public function test_literal_quotes_strings(): void
    {
        $this->assertSame("'x'", $this->quoter->literal('x'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Support/HiveValueQuoterTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

`src/Support/HiveValueQuoter.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Support;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;
use Stringable;

/**
 * Renders PHP values as Hive SQL literals.
 *
 * This exists because PDO_ODBC does not implement PDO::quote() — it returns
 * false, which silently collapses to an empty string and emits malformed SQL.
 * Hive uses C-style escaping inside string literals.
 */
final class HiveValueQuoter
{
    /**
     * Escape sequences applied simultaneously, so replacements are never
     * re-processed and a backslash cannot be escaped twice.
     *
     * @var array<string, string>
     */
    private const ESCAPES = [
        '\\' => '\\\\',
        "'" => "\\'",
        "\n" => '\\n',
        "\r" => '\\r',
        "\t" => '\\t',
        "\0" => '\\0',
    ];

    /**
     * Escape a string and wrap it in single quotes.
     */
    public function quoteString(string $value): string
    {
        return "'" . strtr($value, self::ESCAPES) . "'";
    }

    /**
     * Render any supported value as a Hive SQL literal.
     */
    public function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => $this->quoteString($value),
            $value instanceof DateTimeInterface => $this->quoteString(
                $value->format('Y-m-d H:i:s')
            ),
            $value instanceof BackedEnum => $this->literal($value->value),
            $value instanceof Stringable => $this->quoteString((string) $value),
            default => throw new InvalidArgumentException(
                'Cannot render value of type ' . get_debug_type($value) . ' as a Hive literal.'
            ),
        };
    }
}
```

`strtr()` with an array applies all replacements in a single pass, so the
backslash rule cannot re-process the backslashes introduced by the quote rule.
Doing this with sequential `str_replace()` calls would double-escape.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Support/HiveValueQuoterTest.php`
Expected: PASS, 10 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Support/HiveValueQuoter.php tests/Unit/Support/HiveValueQuoterTest.php
git commit -m "feat: add HiveValueQuoter, replacing unsupported PDO::quote()"
```

---

### Task 5: HiveTableOptions

**Files:**
- Create: `src/Support/HiveTableOptions.php`, `tests/Unit/Support/HiveTableOptionsTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `HiveTableOptions` with nullable public readonly-style accessors `charset()`, `storedAs()`, `delimiter()`, `location()` and corresponding fluent setters `setCharset(?string)`, `setStoredAs(?string)`, `setDelimiter(?string)`, `setLocation(?string)`, each returning `self`. Consumed by `HiveBlueprint` (Task 6) and `HiveSchemaGrammar` (Task 7).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Support/HiveTableOptionsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Sukhil\Database\Hive\Support\HiveTableOptions;

final class HiveTableOptionsTest extends TestCase
{
    public function test_all_options_default_to_null(): void
    {
        $options = new HiveTableOptions();

        $this->assertNull($options->charset());
        $this->assertNull($options->storedAs());
        $this->assertNull($options->delimiter());
        $this->assertNull($options->location());
    }

    public function test_setters_are_fluent_and_store_values(): void
    {
        $options = (new HiveTableOptions())
            ->setCharset('UTF-8')
            ->setStoredAs('ORC')
            ->setDelimiter(',')
            ->setLocation('/warehouse/events');

        $this->assertSame('UTF-8', $options->charset());
        $this->assertSame('ORC', $options->storedAs());
        $this->assertSame(',', $options->delimiter());
        $this->assertSame('/warehouse/events', $options->location());
    }

    public function test_it_reports_whether_any_option_is_set(): void
    {
        $this->assertTrue((new HiveTableOptions())->isEmpty());
        $this->assertFalse((new HiveTableOptions())->setStoredAs('ORC')->isEmpty());
        $this->assertFalse((new HiveTableOptions())->setLocation('/w')->isEmpty());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Support/HiveTableOptionsTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

`src/Support/HiveTableOptions.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Support;

/**
 * Hive-specific CREATE TABLE options.
 *
 * Replaces the dynamic Blueprint properties used before v7 ($blueprint->format,
 * ->location, ->delimiter, ->charset), which relied on dynamic property creation
 * deprecated in PHP 8.2.
 */
final class HiveTableOptions
{
    private ?string $charset = null;

    private ?string $storedAs = null;

    private ?string $delimiter = null;

    private ?string $location = null;

    public function charset(): ?string
    {
        return $this->charset;
    }

    public function storedAs(): ?string
    {
        return $this->storedAs;
    }

    public function delimiter(): ?string
    {
        return $this->delimiter;
    }

    public function location(): ?string
    {
        return $this->location;
    }

    public function setCharset(?string $charset): self
    {
        $this->charset = $charset;

        return $this;
    }

    public function setStoredAs(?string $format): self
    {
        $this->storedAs = $format;

        return $this;
    }

    public function setDelimiter(?string $delimiter): self
    {
        $this->delimiter = $delimiter;

        return $this;
    }

    public function setLocation(?string $location): self
    {
        $this->location = $location;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->charset === null
            && $this->storedAs === null
            && $this->delimiter === null
            && $this->location === null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Support/HiveTableOptionsTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Support/HiveTableOptions.php tests/Unit/Support/HiveTableOptionsTest.php
git commit -m "feat: add typed HiveTableOptions replacing dynamic properties"
```

---

### Task 6: HiveBlueprint

**Files:**
- Create: `src/Schema/HiveBlueprint.php`, `tests/Unit/Schema/HiveBlueprintTest.php`
- Delete: `src/Schema/HiveBlueprint.php` is a rewrite — the old file at this path is replaced wholesale.

**Interfaces:**
- Consumes: `HiveTableOptions` (Task 5)
- Produces:
  - `HiveBlueprint::varChar(string $column, ?int $length = null): ColumnDefinition`
  - `HiveBlueprint::storedAs(string $format): self`
  - `HiveBlueprint::location(string $path): self`
  - `HiveBlueprint::delimiter(string $delimiter): self`
  - `HiveBlueprint::charset(string $charset): self`
  - `HiveBlueprint::hiveOptions(): HiveTableOptions`
  - Consumed by `HiveSchemaGrammar` (Task 7) and `HiveSchemaBuilder` (Task 8).

**Critical:** this class must **not** declare `__construct`. The parent's constructor signature differs between Laravel 11 and 12; by not overriding it, one class serves both.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Schema/HiveBlueprintTest.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Sukhil\Database\Hive\Schema\HiveBlueprint;
use Sukhil\Database\Hive\Tests\Support\BlueprintFactory;

final class HiveBlueprintTest extends TestCase
{
    public function test_it_does_not_declare_its_own_constructor(): void
    {
        // Guards the compatibility strategy: the parent constructor signature
        // differs between Laravel 11 and 12, so overriding it would break one.
        $constructor = (new ReflectionClass(HiveBlueprint::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertNotSame(
            HiveBlueprint::class,
            $constructor->getDeclaringClass()->getName(),
            'HiveBlueprint must not declare __construct.'
        );
    }

    public function test_var_char_defaults_to_the_hive_maximum_length(): void
    {
        $blueprint = BlueprintFactory::make('sample');
        $column = $blueprint->varChar('name');

        $this->assertSame('varChar', $column->get('type'));
        $this->assertSame(65535, $column->get('length'));
    }

    public function test_var_char_accepts_an_explicit_length(): void
    {
        $blueprint = BlueprintFactory::make('sample');
        $column = $blueprint->varChar('name', 120);

        $this->assertSame(120, $column->get('length'));
    }

    public function test_table_option_methods_are_fluent_and_recorded(): void
    {
        $blueprint = BlueprintFactory::make('sample');

        $result = $blueprint
            ->storedAs('ORC')
            ->location('/warehouse/sample')
            ->delimiter(',')
            ->charset('UTF-8');

        $this->assertSame($blueprint, $result);
        $this->assertSame('ORC', $blueprint->hiveOptions()->storedAs());
        $this->assertSame('/warehouse/sample', $blueprint->hiveOptions()->location());
        $this->assertSame(',', $blueprint->hiveOptions()->delimiter());
        $this->assertSame('UTF-8', $blueprint->hiveOptions()->charset());
    }
}
```

- [ ] **Step 2: Write the shared blueprint factory**

Both Laravel versions need constructing differently, and several later test files need this. `tests/Support/BlueprintFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Support;

use Closure;
use Illuminate\Database\Connection;
use PDO;
use Sukhil\Database\Hive\Schema\HiveBlueprint;
use Sukhil\Database\Hive\Support\IlluminateVersion;

/**
 * Builds a HiveBlueprint under whichever Laravel version is installed.
 */
final class BlueprintFactory
{
    /**
     * Build a connection that already carries a schema grammar.
     *
     * A bare `new Connection(...)` is NOT usable: Laravel 12 declares
     * `protected Grammar $grammar` on Blueprint (non-nullable) and assigns it
     * from `$connection->getSchemaGrammar()` in the constructor. The base
     * Connection's `getDefaultSchemaGrammar()` is a no-op, so that yields null
     * and throws "Cannot assign null to property Blueprint::$grammar".
     */
    public static function connection(?SchemaGrammar $schemaGrammar = null): Connection
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));

        if ($schemaGrammar !== null) {
            $connection->setSchemaGrammar($schemaGrammar);
        } else {
            $connection->useDefaultSchemaGrammar();
        }

        return $connection;
    }

    public static function make(
        string $table,
        ?Closure $callback = null,
        ?SchemaGrammar $schemaGrammar = null,
    ): HiveBlueprint {
        $connection = self::connection($schemaGrammar);

        if (IlluminateVersion::detect()->usesConnectionAwareSchemaApi()) {
            /** @phpstan-ignore-next-line Laravel 12 signature */
            return new HiveBlueprint($connection, $table, $callback);
        }

        /** @phpstan-ignore-next-line Laravel 11 signature */
        return new HiveBlueprint($table, $callback);
    }

    /**
     * Compile a blueprint to SQL under either Laravel version.
     *
     * Laravel 11: `toSql(Connection $connection, Grammar $grammar)`.
     * Laravel 12: `toSql()` — the blueprint already holds both.
     *
     * @return array<int, string>
     */
    public static function toSql(
        HiveBlueprint $blueprint,
        Connection $connection,
        SchemaGrammar $grammar,
    ): array {
        if (IlluminateVersion::detect()->usesConnectionAwareSchemaApi()) {
            /** @phpstan-ignore-next-line Laravel 12 signature */
            return $blueprint->toSql();
        }

        /** @phpstan-ignore-next-line Laravel 11 signature */
        return $blueprint->toSql($connection, $grammar);
    }
}
```

Imports required: `Illuminate\Database\SQLiteConnection`, and
`Illuminate\Database\Schema\Grammars\Grammar as SchemaGrammar`.

- [ ] **Step 3: Run test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Schema/HiveBlueprintTest.php`
Expected: FAIL — `HiveBlueprint` has no `varChar`/`storedAs` methods, or the old class still declares a constructor.

- [ ] **Step 4: Write the implementation**

`src/Schema/HiveBlueprint.php` (replaces the existing file entirely):
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Sukhil\Database\Hive\Support\HiveTableOptions;

/**
 * Blueprint with Hive-specific column types and table options.
 *
 * Deliberately declares no constructor: Blueprint::__construct differs between
 * Laravel 11 ($table, $callback, $prefix) and Laravel 12 (Connection, $table,
 * $callback). Not overriding it lets one class serve both. Construction is
 * handled by HiveSchemaBuilder.
 */
class HiveBlueprint extends Blueprint
{
    private ?HiveTableOptions $hiveOptions = null;

    /**
     * Create a varchar column. Hive allows 1 to 65535 characters; values
     * exceeding the limit are silently truncated by Hive itself.
     */
    public function varChar(string $column, ?int $length = null): ColumnDefinition
    {
        return $this->addColumn('varChar', $column, [
            'length' => $length ?? 65535,
        ]);
    }

    /**
     * Set the storage format, for example 'ORC'.
     */
    public function storedAs(string $format): self
    {
        $this->hiveOptions()->setStoredAs($format);

        return $this;
    }

    /**
     * Set the HDFS location backing this table.
     */
    public function location(string $path): self
    {
        $this->hiveOptions()->setLocation($path);

        return $this;
    }

    /**
     * Set the field delimiter for ROW FORMAT DELIMITED.
     */
    public function delimiter(string $delimiter): self
    {
        $this->hiveOptions()->setDelimiter($delimiter);

        return $this;
    }

    /**
     * Set the serialization charset, emitted as SerDe properties.
     *
     * The parameter is deliberately untyped: `Blueprint::charset($charset)`
     * already exists upstream with no parameter type, and narrowing it to
     * `string` in a child is a contravariance violation that fatals. The
     * parent's `$charset` property is kept in sync so any upstream code
     * reading it still sees the right value.
     *
     * @param  string  $charset
     */
    public function charset($charset): self
    {
        parent::charset($charset);

        $this->hiveOptions()->setCharset($charset);

        return $this;
    }

    public function hiveOptions(): HiveTableOptions
    {
        return $this->hiveOptions ??= new HiveTableOptions();
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Schema/HiveBlueprintTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
git add src/Schema/HiveBlueprint.php tests/Unit/Schema/HiveBlueprintTest.php tests/Support/BlueprintFactory.php
git commit -m "feat: rewrite HiveBlueprint with typed table options, no constructor override"
```

---

### Task 7: HiveSchemaGrammar

The largest class. Carries all Hive type mappings plus `compileCreate`.

**Files:**
- Create: `src/Schema/Grammars/HiveSchemaGrammar.php`, `tests/Unit/Schema/HiveSchemaGrammarTest.php`
- Delete: `src/Schema/Grammars/HiveGrammar.php`

**Interfaces:**
- Consumes: `HiveBlueprint` (Task 6), `HiveTableOptions` (Task 5)
- Produces: `HiveSchemaGrammar::compileCreate(Blueprint $blueprint, Fluent $command, ?Connection $connection = null): string`. Consumed by `HiveConnection` (Task 10) and the golden parity test (Task 12).

**Critical:** the `?Connection $connection = null` third parameter is what makes one class satisfy both parents. Laravel 11's parent declares three required parameters; Laravel 12's declares two. A child may widen, so an optional third works against both. Do not remove it, and do not make it required.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Schema/HiveSchemaGrammarTest.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Sukhil\Database\Hive\Schema\Grammars\HiveSchemaGrammar;
use Sukhil\Database\Hive\Schema\HiveBlueprint;
use Sukhil\Database\Hive\Tests\Support\BlueprintFactory;

final class HiveSchemaGrammarTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function compile(callable $definition): array
    {
        // The grammar must receive a connection: on Laravel 12 the parent
        // constructor requires it, and wrapTable() reads the prefix from it.
        // The blueprint must be built against a connection already carrying
        // that grammar, because Laravel 12 captures it at construction time.
        $connection = BlueprintFactory::connection();
        $grammar = new HiveSchemaGrammar($connection);
        $connection->setSchemaGrammar($grammar);

        $blueprint = BlueprintFactory::make('sample_table', function (HiveBlueprint $table) use ($definition): void {
            $definition($table);
        }, $grammar);
        $blueprint->create();

        return BlueprintFactory::toSql($blueprint, $connection, $grammar);
    }

    public function test_it_maps_numeric_types(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->integer('a');
            $table->bigInteger('b');
            $table->smallInteger('c');
            $table->tinyInteger('d');
            $table->float('e');
            $table->double('f');
        });

        $this->assertStringContainsString('a int', $sql[0]);
        $this->assertStringContainsString('b bigint', $sql[0]);
        $this->assertStringContainsString('c smallint', $sql[0]);
        $this->assertStringContainsString('d tinyint', $sql[0]);
        $this->assertStringContainsString('e float', $sql[0]);
        $this->assertStringContainsString('f double', $sql[0]);
    }

    public function test_it_maps_string_types(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('a');
            $table->char('b', 10);
            $table->varChar('c', 100);
            $table->text('d');
        });

        $this->assertStringContainsString('a string', $sql[0]);
        $this->assertStringContainsString('b char(10)', $sql[0]);
        $this->assertStringContainsString('c varchar(100)', $sql[0]);
        $this->assertStringContainsString('d varchar(65535)', $sql[0]);
    }

    public function test_it_maps_temporal_and_misc_types(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->date('a');
            $table->timestamp('b');
            $table->dateTime('c');
            $table->boolean('d');
            $table->binary('e');
        });

        $this->assertStringContainsString('a date', $sql[0]);
        $this->assertStringContainsString('b timestamp', $sql[0]);
        $this->assertStringContainsString('c timestamp', $sql[0]);
        $this->assertStringContainsString('d boolean', $sql[0]);
        $this->assertStringContainsString('e binary', $sql[0]);
    }

    public function test_it_drops_unsupported_column_modifiers(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('a')->nullable();
            $table->integer('b')->default(7);
            $table->integer('c')->unsigned();
        });

        $this->assertStringNotContainsString('null', $sql[0]);
        $this->assertStringNotContainsString('default', $sql[0]);
        $this->assertStringNotContainsString('unsigned', $sql[0]);
    }

    public function test_it_does_not_wrap_identifiers(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('name');
        });

        $this->assertStringStartsWith('create table sample_table (', $sql[0]);
    }

    public function test_it_emits_stored_as_orc(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('name');
            $table->storedAs('ORC');
        });

        $this->assertStringContainsString('STORED AS ORC', $sql[0]);
    }

    public function test_it_emits_location(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('name');
            $table->location('/warehouse/sample');
        });

        $this->assertStringContainsString(" LOCATION '/warehouse/sample'", $sql[0]);
    }

    public function test_it_emits_only_one_row_format_clause(): void
    {
        // Hive permits a single ROW FORMAT clause. v6 emitted both the SerDe
        // and DELIMITED forms, producing DDL Hive rejects.
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('name');
            $table->charset('UTF-8');
            $table->delimiter(',');
        });

        $this->assertSame(1, substr_count($sql[0], 'ROW FORMAT'));
        $this->assertStringContainsString('ROW FORMAT SERDE', $sql[0]);
        $this->assertStringNotContainsString('DELIMITED', $sql[0]);
    }

    public function test_it_separates_the_column_list_from_table_options(): void
    {
        // v6 emitted ")ROW FORMAT SERDE" with no separating space.
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('name');
            $table->charset('UTF-8');
        });

        $this->assertStringContainsString(') ROW FORMAT SERDE', $sql[0]);
    }

    public function test_it_orders_clauses_per_hiveql(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('name');
            $table->delimiter(',');
            $table->storedAs('ORC');
            $table->location('/warehouse/sample');
        });

        $rowFormat = strpos($sql[0], 'ROW FORMAT');
        $storedAs = strpos($sql[0], 'STORED AS');
        $location = strpos($sql[0], 'LOCATION');

        $this->assertNotFalse($rowFormat);
        $this->assertNotFalse($storedAs);
        $this->assertNotFalse($location);
        $this->assertLessThan($storedAs, $rowFormat);
        $this->assertLessThan($location, $storedAs);
    }

    public function test_it_emits_row_format_delimited(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('name');
            $table->delimiter(',');
        });

        $this->assertStringContainsString("ROW FORMAT DELIMITED FIELDS TERMINATED BY ','", $sql[0]);
    }

    public function test_it_emits_serde_properties_for_charset(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('name');
            $table->charset('UTF-8');
        });

        $this->assertStringContainsString('LazySimpleSerDe', $sql[0]);
        $this->assertStringContainsString("'serialization.encoding'='UTF-8'", $sql[0]);
    }

    public function test_compile_create_accepts_an_optional_third_argument(): void
    {
        // Guards the dual-version strategy. Laravel 11 passes a Connection
        // here; Laravel 12 does not.
        $method = new \ReflectionMethod(HiveSchemaGrammar::class, 'compileCreate');
        $third = $method->getParameters()[2] ?? null;

        $this->assertNotNull($third, 'compileCreate must accept a third parameter.');
        $this->assertTrue($third->isOptional(), 'The third parameter must be optional.');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Schema/HiveSchemaGrammarTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

`src/Schema/Grammars/HiveSchemaGrammar.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Schema\Grammars;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;
use Sukhil\Database\Hive\Schema\HiveBlueprint;
use Sukhil\Database\Hive\Support\HiveTableOptions;

/**
 * Compiles schema operations into HiveQL DDL.
 *
 * Hive has no NOT NULL, DEFAULT, UNSIGNED or AUTO_INCREMENT, so $modifiers is
 * intentionally empty and those calls are silently dropped. See docs/limitations.md.
 */
class HiveSchemaGrammar extends Grammar
{
    /**
     * Accept an optional Connection so one class serves both Laravel versions.
     *
     * Laravel 12's parent Grammar declares __construct(Connection) as required
     * and has no setConnection(). Laravel 11's parent declares no constructor
     * but does have setConnection(). Constructors are exempt from PHP's
     * signature-compatibility rules, so this declaration is legal against both.
     */
    public function __construct(?Connection $connection = null)
    {
        if ($connection === null) {
            return;
        }

        if (method_exists(parent::class, '__construct')) {
            parent::__construct($connection);   // Laravel 12
        } else {
            $this->setConnection($connection);  // Laravel 11
        }
    }

    /**
     * Hive supports no column modifiers.
     *
     * @var array<int, string>
     */
    protected $modifiers = [];

    /**
     * Hive has no auto-incrementing column types.
     *
     * @var array<int, string>
     */
    protected $serials = [];

    /**
     * Hive identifiers are not quoted; only embedded double quotes are escaped.
     */
    protected function wrapValue($value): string
    {
        if ($value === '*') {
            return $value;
        }

        return str_replace('"', '""', (string) $value);
    }

    /**
     * Compile a create table command.
     *
     * The third parameter is optional so this single declaration satisfies both
     * Laravel 11 (which declares it required) and Laravel 12 (which omits it).
     */
    public function compileCreate(
        Blueprint $blueprint,
        Fluent $command,
        ?Connection $connection = null,
    ): string {
        $columns = implode(', ', $this->getColumns($blueprint));

        $sql = 'create table ' . $this->wrapTable($blueprint) . " ($columns)";

        return $sql . $this->compileTableOptions($blueprint);
    }

    /**
     * Append Hive-specific table clauses in the order Hive expects.
     */
    protected function compileTableOptions(Blueprint $blueprint): string
    {
        if (! $blueprint instanceof HiveBlueprint) {
            return '';
        }

        $options = $blueprint->hiveOptions();

        if ($options->isEmpty()) {
            return '';
        }

        // HiveQL clause order is fixed: ROW FORMAT, then STORED AS, then LOCATION.
        return $this->rowFormatClause($options)
            . $this->storedAsClause($options)
            . $this->locationClause($options);
    }

    /**
     * Hive permits exactly ONE row-format clause per table.
     *
     * When a charset is set the SerDe form wins, because it is the more
     * specific declaration; any delimiter is then ignored. v6 emitted both
     * clauses, which Hive rejects outright.
     */
    protected function rowFormatClause(HiveTableOptions $options): string
    {
        if (($charset = $options->charset()) !== null) {
            return " ROW FORMAT SERDE 'org.apache.hadoop.hive.serde2.lazy.LazySimpleSerDe'"
                . ' WITH SERDEPROPERTIES ('
                . "'serialization.encoding'='{$charset}', "
                . "'store.charset'='{$charset}', "
                . "'retrieve.charset'='{$charset}')";
        }

        if (($delimiter = $options->delimiter()) !== null) {
            return " ROW FORMAT DELIMITED FIELDS TERMINATED BY '{$delimiter}'";
        }

        return '';
    }

    protected function storedAsClause(HiveTableOptions $options): string
    {
        return $options->storedAs() === 'ORC' ? ' STORED AS ORC' : '';
    }

    protected function locationClause(HiveTableOptions $options): string
    {
        if (($location = $options->location()) === null) {
            return '';
        }

        return " LOCATION '{$location}'";
    }

    /**
     * CHAR is fixed-length; shorter values are space-padded. Maximum 255.
     */
    protected function typeChar(Fluent $column): string
    {
        return "char({$column->get('length')})";
    }

    /**
     * Hive STRING is unbounded, unlike VARCHAR.
     */
    protected function typeString(Fluent $column): string
    {
        return 'string';
    }

    /**
     * VARCHAR takes a length between 1 and 65535. Hive truncates silently.
     */
    protected function typeVarChar(Fluent $column): string
    {
        return 'varchar(' . ($column->get('length') ?? 65535) . ')';
    }

    protected function typeText(Fluent $column): string
    {
        return $this->typeVarChar($column);
    }

    protected function typeMediumText(Fluent $column): string
    {
        return $this->typeVarChar($column);
    }

    protected function typeLongText(Fluent $column): string
    {
        return $this->typeVarChar($column);
    }

    protected function typeBigInteger(Fluent $column): string
    {
        return 'bigint';
    }

    protected function typeInteger(Fluent $column): string
    {
        return 'int';
    }

    protected function typeMediumInteger(Fluent $column): string
    {
        return 'int';
    }

    protected function typeTinyInteger(Fluent $column): string
    {
        return 'tinyint';
    }

    protected function typeSmallInteger(Fluent $column): string
    {
        return 'smallint';
    }

    protected function typeNumeric(Fluent $column): string
    {
        return "numeric({$column->get('total')}, {$column->get('places')})";
    }

    protected function typeFloat(Fluent $column): string
    {
        return 'float';
    }

    protected function typeDouble(Fluent $column): string
    {
        $total = $column->get('total');
        $places = $column->get('places');

        if ($total && $places) {
            return "double({$total}, {$places})";
        }

        return 'double';
    }

    protected function typeDecimal(Fluent $column): string
    {
        return "decimal({$column->get('total')}, {$column->get('places')})";
    }

    protected function typeBoolean(Fluent $column): string
    {
        return 'boolean';
    }

    protected function typeDate(Fluent $column): string
    {
        return 'date';
    }

    protected function typeDateTime(Fluent $column): string
    {
        return $this->typeTimestamp($column);
    }

    protected function typeTimestamp(Fluent $column): string
    {
        return 'timestamp';
    }

    protected function typeBinary(Fluent $column): string
    {
        return 'binary';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Schema/HiveSchemaGrammarTest.php`
Expected: PASS, 10 tests.

- [ ] **Step 5: Delete the old grammar**

```bash
git rm src/Schema/Grammars/HiveGrammar.php
```

- [ ] **Step 6: Commit**

```bash
git add src/Schema/Grammars/HiveSchemaGrammar.php tests/Unit/Schema/HiveSchemaGrammarTest.php
git commit -m "feat: rewrite schema grammar as HiveSchemaGrammar with dual-version compileCreate"
```

---

### Task 8: HiveSchemaBuilder

One of the two files permitted to branch on Laravel version.

**Files:**
- Create: `src/Schema/HiveSchemaBuilder.php`, `tests/Unit/Schema/HiveSchemaBuilderTest.php`
- Delete: `src/Schema/Builder.php`

**Note:** this is a unit test, not a feature test. The builder is constructed directly with a hand-made connection, so it needs neither the service provider (Task 11) nor `HiveConnection` (Task 10) — keeping this task genuinely independent and avoiding a test that asserts nothing while waiting for a later task.

**Interfaces:**
- Consumes: `IlluminateVersion` (Task 3), `HiveBlueprint` (Task 6)
- Produces: `HiveSchemaBuilder::createBlueprint(string $table, ?Closure $callback = null): HiveBlueprint`. Consumed by `HiveConnection` (Task 10).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Schema/HiveSchemaBuilderTest.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Sukhil\Database\Hive\Schema\Grammars\HiveSchemaGrammar;
use Sukhil\Database\Hive\Schema\HiveBlueprint;
use Sukhil\Database\Hive\Schema\HiveSchemaBuilder;
use Sukhil\Database\Hive\Tests\Support\BlueprintFactory;

final class HiveSchemaBuilderTest extends TestCase
{
    private function builder(): HiveSchemaBuilder
    {
        // A connection carrying the Hive schema grammar. Avoids depending on
        // HiveConnection (Task 10) or the provider (Task 11).
        //
        // The grammar must be attached BEFORE the builder is constructed:
        // Illuminate's Builder::__construct does
        // `$this->grammar = $connection->getSchemaGrammar()`.
        $connection = BlueprintFactory::connection();
        $connection->setSchemaGrammar(new HiveSchemaGrammar($connection));

        return new HiveSchemaBuilder($connection);
    }

    private function createBlueprint(HiveSchemaBuilder $builder, string $table): mixed
    {
        $method = new ReflectionMethod($builder, 'createBlueprint');

        return $method->invoke($builder, $table, null);
    }

    public function test_it_creates_hive_blueprints_under_the_installed_laravel(): void
    {
        $blueprint = $this->createBlueprint($this->builder(), 'sample_table');

        $this->assertInstanceOf(HiveBlueprint::class, $blueprint);
        $this->assertSame('sample_table', $blueprint->getTable());
    }

    public function test_a_registered_resolver_takes_precedence(): void
    {
        $builder = $this->builder();
        $sentinel = $this->createBlueprint($builder, 'ignored');

        $builder->blueprintResolver(fn (): HiveBlueprint => $sentinel);

        $this->assertSame($sentinel, $this->createBlueprint($builder, 'other_table'));
    }
}
```

**Note:** this constructs the builder directly and never opens a network connection, so no ODBC driver is required.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Schema/HiveSchemaBuilderTest.php`
Expected: FAIL — `HiveSchemaBuilder` not found.

- [ ] **Step 3: Write the implementation**

`src/Schema/HiveSchemaBuilder.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Schema;

use Closure;
use Illuminate\Database\Schema\Builder;
use Sukhil\Database\Hive\Support\IlluminateVersion;

/**
 * Schema builder producing HiveBlueprint instances.
 *
 * One of only two classes in this package permitted to branch on the installed
 * Laravel version: Blueprint::__construct takes (Connection, $table, $callback)
 * on Laravel 12 and ($table, $callback, $prefix) on Laravel 11.
 */
class HiveSchemaBuilder extends Builder
{
    private ?IlluminateVersion $illuminateVersion = null;

    public function setIlluminateVersion(IlluminateVersion $version): self
    {
        $this->illuminateVersion = $version;

        return $this;
    }

    protected function illuminateVersion(): IlluminateVersion
    {
        return $this->illuminateVersion ??= IlluminateVersion::detect();
    }

    /**
     * Create a blueprint using whichever constructor the installed Laravel declares.
     */
    protected function createBlueprint($table, ?Closure $callback = null): HiveBlueprint
    {
        if (isset($this->resolver)) {
            // The resolver's argument shape is itself version-divergent, and
            // this is a documented public extension point — a user's resolver
            // is written against whichever Laravel they run, so we must call
            // it the way their framework would:
            //   Laravel 11: ($table, $callback, $prefix)
            //   Laravel 12: ($connection, $table, $callback)
            if ($this->illuminateVersion()->usesConnectionAwareSchemaApi()) {
                /** @var HiveBlueprint */
                return call_user_func($this->resolver, $this->connection, $table, $callback);
            }

            $prefix = $this->connection->getConfig('prefix_indexes')
                ? $this->connection->getConfig('prefix')
                : '';

            /** @var HiveBlueprint */
            return call_user_func($this->resolver, $table, $callback, $prefix);
        }

        if ($this->illuminateVersion()->usesConnectionAwareSchemaApi()) {
            /** @phpstan-ignore-next-line Laravel 12 signature */
            return new HiveBlueprint($this->connection, $table, $callback);
        }

        /** @phpstan-ignore-next-line Laravel 11 signature */
        return new HiveBlueprint($table, $callback);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Schema/HiveSchemaBuilderTest.php`
Expected: PASS, 2 tests. No test may be skipped — if either cannot run, report rather than skipping.

- [ ] **Step 5: Delete the old builder**

```bash
git rm src/Schema/Builder.php
```

- [ ] **Step 6: Commit**

```bash
git add src/Schema/HiveSchemaBuilder.php tests/Unit/Schema/HiveSchemaBuilderTest.php
git commit -m "feat: add HiveSchemaBuilder with version-aware blueprint construction"
```

---

### Task 9: HiveQueryGrammar and HiveProcessor

**Files:**
- Create: `src/Query/Grammars/HiveQueryGrammar.php`, `tests/Unit/Query/HiveQueryGrammarTest.php`
- Modify: `src/Query/Processors/HiveProcessor.php`
- Delete: `src/Query/Grammars/HiveGrammar.php`

**Interfaces:**
- Consumes: `HiveValueQuoter` (Task 4)
- Produces:
  - `HiveQueryGrammar::__construct(?HiveValueQuoter $quoter = null)`
  - `HiveQueryGrammar::compileInsert(Builder $query, array $values): string`
  - `HiveQueryGrammar::wrapTable($table): string` — returns the table unwrapped
  - Consumed by `HiveConnection` (Task 10).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Query/HiveQueryGrammarTest.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Query;

use Illuminate\Database\Query\Builder;
use PHPUnit\Framework\TestCase;
use Sukhil\Database\Hive\Query\Grammars\HiveQueryGrammar;
use Sukhil\Database\Hive\Tests\Support\BlueprintFactory;

final class HiveQueryGrammarTest extends TestCase
{
    private HiveQueryGrammar $grammar;

    protected function setUp(): void
    {
        // Laravel 12's parent Grammar constructor requires a connection.
        $this->grammar = new HiveQueryGrammar(BlueprintFactory::connection());
    }

    private function query(string $table): Builder
    {
        $builder = $this->createMock(Builder::class);
        $builder->from = $table;

        return $builder;
    }

    public function test_it_does_not_wrap_table_names(): void
    {
        $this->assertSame('events', $this->grammar->wrapTable('events'));
    }

    public function test_it_compiles_a_single_row_insert(): void
    {
        $sql = $this->grammar->compileInsert(
            $this->query('events'),
            ['name' => 'Alice', 'age' => 30]
        );

        $this->assertSame(
            "insert into events (name, age) values ('Alice', 30)",
            $sql
        );
    }

    public function test_it_compiles_a_batch_insert(): void
    {
        $sql = $this->grammar->compileInsert(
            $this->query('events'),
            [
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob', 'age' => 25],
            ]
        );

        $this->assertSame(
            "insert into events (name, age) values ('Alice', 30), ('Bob', 25)",
            $sql
        );
    }

    public function test_it_escapes_string_values(): void
    {
        $sql = $this->grammar->compileInsert(
            $this->query('events'),
            ['name' => "O'Brien"]
        );

        $this->assertSame("insert into events (name) values ('O\\'Brien')", $sql);
    }

    public function test_it_renders_null_as_the_null_keyword(): void
    {
        // v6 emitted an empty string here, producing malformed SQL.
        $sql = $this->grammar->compileInsert(
            $this->query('events'),
            ['name' => null]
        );

        $this->assertSame('insert into events (name) values (NULL)', $sql);
    }

    public function test_it_never_calls_pdo_quote(): void
    {
        // PDO_ODBC does not implement quote(); it returns false. Guard against
        // any future reintroduction of a PDO dependency in this class.
        $source = file_get_contents(
            __DIR__ . '/../../../src/Query/Grammars/HiveQueryGrammar.php'
        );

        $this->assertIsString($source);
        $this->assertStringNotContainsString('->quote(', $source);
        $this->assertStringNotContainsString('getPdo', $source);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Query/HiveQueryGrammarTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

`src/Query/Grammars/HiveQueryGrammar.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Query\Grammars;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Arr;
use Sukhil\Database\Hive\Support\HiveValueQuoter;

/**
 * Compiles queries into HiveQL.
 *
 * Inserts are emitted as inline literals rather than bound parameters, because
 * the Hive ODBC driver does not handle binding on this path. Escaping goes
 * through HiveValueQuoter — never PDO::quote(), which PDO_ODBC does not
 * implement and which returns false.
 */
class HiveQueryGrammar extends Grammar
{
    private HiveValueQuoter $quoter;

    /**
     * Accept an optional Connection so one class serves both Laravel versions.
     *
     * Laravel 12's parent Grammar requires __construct(Connection) and has no
     * setConnection(); Laravel 11's has no constructor but does have
     * setConnection(). Constructors are exempt from PHP's signature-compatibility
     * rules, so this declaration is legal against both parents.
     */
    public function __construct(?Connection $connection = null, ?HiveValueQuoter $quoter = null)
    {
        $this->quoter = $quoter ?? new HiveValueQuoter();

        if ($connection === null) {
            return;
        }

        if (method_exists(parent::class, '__construct')) {
            parent::__construct($connection);   // Laravel 12
        } else {
            $this->setConnection($connection);  // Laravel 11
        }
    }

    /**
     * Compile an insert statement into HiveQL.
     *
     * @param  array<mixed>  $values
     */
    public function compileInsert(Builder $query, array $values): string
    {
        $table = $this->wrapTable($query->from);

        if ($values === []) {
            return "insert into {$table} default values";
        }

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        /** @var array<string, mixed> $first */
        $first = reset($values);

        $columns = $this->columnize(array_keys($first));
        $rows = $this->compileRows($values);

        return "insert into {$table} ({$columns}) values {$rows}";
    }

    /**
     * @param  array<mixed>  $values
     */
    protected function compileRows(array $values): string
    {
        if (Arr::isAssoc($values)) {
            return $this->compileRow($values);
        }

        return implode(', ', array_map(
            fn (array $row): string => $this->compileRow($row),
            $values
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function compileRow(array $row): string
    {
        return '(' . implode(', ', array_map(
            fn (mixed $value): string => $this->quoter->literal($value),
            $row
        )) . ')';
    }

    /**
     * Hive table identifiers are used verbatim.
     *
     * The `$prefix` parameter is declared optional so this single override is
     * compatible with both parents: Laravel 11 declares `wrapTable($table)`,
     * Laravel 12 declares `wrapTable($table, $prefix = null)`. Omitting it
     * would be an incompatible declaration on Laravel 12 and fatal at load.
     * Hive uses the name verbatim, so the prefix is intentionally ignored.
     *
     * @param  \Illuminate\Contracts\Database\Query\Expression|string  $table
     * @param  string|null  $prefix
     */
    public function wrapTable($table, $prefix = null): string
    {
        return (string) $table;
    }
}
```

- [ ] **Step 4: Add strict types to HiveProcessor**

`src/Query/Processors/HiveProcessor.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Query\Processors;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;

/**
 * Post-processes Hive query results.
 */
class HiveProcessor extends Processor
{
    /**
     * Hive has no sequences or last-insert-id, so the first inserted value is
     * returned as the closest available stand-in.
     *
     * @param  array<string, mixed>  $values
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null): mixed
    {
        $query->getConnection()->insert($sql, $values);

        return $values === [] ? null : reset($values);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Query/`
Expected: PASS, 6 tests.

- [ ] **Step 6: Delete the old query grammar**

```bash
git rm src/Query/Grammars/HiveGrammar.php
```

- [ ] **Step 7: Commit**

```bash
git add src/Query/ tests/Unit/Query/
git commit -m "feat: rewrite query grammar using HiveValueQuoter instead of PDO::quote()"
```

---

### Task 10: HiveConnection and HiveConnector

The second of the two files permitted to branch on Laravel version.

**Files:**
- Modify: `src/HiveConnection.php`, `src/Connectors/HiveConnector.php`
- Create: `tests/Unit/Connectors/HiveConnectorTest.php`, `tests/Unit/HiveConnectionTest.php`

**Interfaces:**
- Consumes: `IlluminateVersion` (Task 3), `HiveQueryGrammar` (Task 9), `HiveSchemaGrammar` (Task 7), `HiveSchemaBuilder` (Task 8), `HiveProcessor` (Task 9)
- Produces: `HiveConnection` with `getSchemaBuilder(): HiveSchemaBuilder` and a `statement()` that uses `PDO::exec`. Consumed by `HiveServiceProvider` (Task 11).

**Critical:** `HiveConnection` must **not** declare `__construct`. The parent accepts `\PDO|(\Closure(): \PDO)`; the old `PDO $pdo` hint fatals when Laravel passes a closure for lazy connections.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Connectors/HiveConnectorTest.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Connectors;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Sukhil\Database\Hive\Connectors\HiveConnector;

final class HiveConnectorTest extends TestCase
{
    private function dsn(array $config): ?string
    {
        $method = new ReflectionMethod(HiveConnector::class, 'getDsn');

        return $method->invoke(new HiveConnector(), $config);
    }

    public function test_it_prefixes_a_bare_dsn_with_odbc(): void
    {
        $this->assertSame(
            'odbc:Driver=Hive;Host=localhost',
            $this->dsn(['dsn' => 'Driver=Hive;Host=localhost'])
        );
    }

    public function test_it_leaves_an_already_prefixed_dsn_alone(): void
    {
        $this->assertSame(
            'odbc:Driver=Hive',
            $this->dsn(['dsn' => 'odbc:Driver=Hive'])
        );
    }

    public function test_it_returns_null_for_a_missing_dsn(): void
    {
        $this->assertNull($this->dsn([]));
    }

    public function test_it_returns_null_for_an_empty_dsn(): void
    {
        $this->assertNull($this->dsn(['dsn' => '']));
    }
}
```

`tests/Unit/HiveConnectionTest.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Sukhil\Database\Hive\HiveConnection;

final class HiveConnectionTest extends TestCase
{
    public function test_it_does_not_declare_its_own_constructor(): void
    {
        // The parent accepts \PDO|(\Closure(): \PDO). Declaring PDO $pdo here
        // fatals when Laravel passes a closure for a lazy connection.
        $constructor = (new ReflectionClass(HiveConnection::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertNotSame(
            HiveConnection::class,
            $constructor->getDeclaringClass()->getName(),
            'HiveConnection must not declare __construct.'
        );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/HiveConnectionTest.php tests/Unit/Connectors/`
Expected: FAIL — the connection still declares a constructor; connector returns `''` rather than `null` for an empty DSN.

- [ ] **Step 3: Rewrite HiveConnection**

`src/HiveConnection.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive;

use Illuminate\Database\Connection;
use Sukhil\Database\Hive\Query\Grammars\HiveQueryGrammar;
use Sukhil\Database\Hive\Query\Processors\HiveProcessor;
use Sukhil\Database\Hive\Schema\Grammars\HiveSchemaGrammar;
use Sukhil\Database\Hive\Schema\HiveSchemaBuilder;
use Sukhil\Database\Hive\Support\IlluminateVersion;

/**
 * A Laravel database connection backed by Apache Hive over ODBC.
 *
 * Declares no constructor: the parent accepts \PDO|(\Closure(): \PDO), and
 * narrowing that in a child both violates contravariance and breaks lazy
 * connections.
 *
 * One of only two classes permitted to branch on the installed Laravel version:
 * Laravel 12 passes a Connection to grammar constructors, Laravel 11 uses
 * withTablePrefix().
 */
class HiveConnection extends Connection
{
    private ?IlluminateVersion $illuminateVersion = null;

    protected function illuminateVersion(): IlluminateVersion
    {
        return $this->illuminateVersion ??= IlluminateVersion::detect();
    }

    public function getSchemaBuilder(): HiveSchemaBuilder
    {
        if ($this->schemaGrammar === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new HiveSchemaBuilder($this);
    }

    protected function getDefaultQueryGrammar(): HiveQueryGrammar
    {
        return $this->configureGrammar(new HiveQueryGrammar($this));
    }

    protected function getDefaultSchemaGrammar(): HiveSchemaGrammar
    {
        return $this->configureGrammar(new HiveSchemaGrammar($this));
    }

    protected function getDefaultPostProcessor(): HiveProcessor
    {
        return new HiveProcessor();
    }

    /**
     * Apply the table prefix using whichever mechanism this Laravel provides.
     *
     * On Laravel 12 the grammar derives the prefix from the connection it was
     * constructed with, so there is nothing further to do. On Laravel 11 the
     * prefix is a separate property, set via the connection's withTablePrefix().
     *
     * @template TGrammar of object
     *
     * @param  TGrammar  $grammar
     * @return TGrammar
     */
    protected function configureGrammar(object $grammar): object
    {
        if ($this->illuminateVersion()->usesConnectionAwareSchemaApi()) {
            return $grammar;
        }

        /** @phpstan-ignore-next-line withTablePrefix exists only on Laravel 11 */
        return $this->withTablePrefix($grammar);
    }

    /**
     * Execute an SQL statement and return its result.
     *
     * Uses PDO::exec rather than prepare: the Hive ODBC driver does not support
     * prepared DDL statements.
     *
     * @param  array<mixed>  $bindings
     */
    public function statement($query, $bindings = []): bool
    {
        return (bool) $this->run($query, $bindings, function (string $query): int|false {
            if ($this->pretending()) {
                return 0;
            }

            return $this->getPdo()->exec($query);
        });
    }
}
```

**Verified against both branches, and the asymmetry is counter-intuitive — do not "simplify" it away:**

| | Laravel 11 | Laravel 12 |
|---|---|---|
| `Grammar::__construct` | none declared | `__construct(Connection)`, required |
| `setConnection()` | exists | **does not exist** |
| `withTablePrefix()` on Connection | exists | removed |

So Laravel 12 takes the connection through the constructor, and Laravel 11 through `setConnection()` plus a separate `withTablePrefix()` for the prefix. That is why both grammar classes declare `__construct(?Connection $connection = null)` and dispatch on `method_exists(parent::class, '__construct')`.

- [ ] **Step 4: Rewrite HiveConnector**

`src/Connectors/HiveConnector.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Connectors;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Support\Str;
use PDO;

/**
 * Opens PDO_ODBC connections to Hive.
 */
class HiveConnector extends Connector implements ConnectorInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): PDO
    {
        return $this->createConnection(
            (string) $this->getDsn($config),
            $config,
            $this->getOptions($config)
        );
    }

    /**
     * Build the ODBC DSN, adding the odbc: scheme when absent.
     *
     * @param  array<string, mixed>  $config
     */
    protected function getDsn(array $config): ?string
    {
        $dsn = $config['dsn'] ?? null;

        if (! is_string($dsn) || $dsn === '') {
            return null;
        }

        return Str::startsWith($dsn, 'odbc') ? $dsn : "odbc:{$dsn}";
    }
}
```

- [ ] **Step 5: Confirm the installed grammar shape matches the table above**

Run: `docker compose run --rm php grep -n "function setConnection\|function __construct" vendor/laravel/framework/src/Illuminate/Database/Grammar.php`
Expected, on Laravel 12: a `__construct(Connection $connection)` and **no** `setConnection`. On Laravel 11: a `setConnection` and **no** constructor.

If what you see contradicts the table, stop and report rather than adapting the code — it would mean the framework changed again and the compatibility strategy needs re-deriving.

- [ ] **Step 6: Run tests to verify they pass**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/`
Expected: PASS, all unit tests.

- [ ] **Step 7: Commit**

```bash
git add src/HiveConnection.php src/Connectors/HiveConnector.php tests/Unit/
git commit -m "feat: modernize HiveConnection and HiveConnector for Laravel 11/12"
```

---

### Task 11: Service provider and config

**Files:**
- Modify: `src/HiveServiceProvider.php`
- Create: `config/hive.php`, `tests/Feature/ServiceProviderTest.php`
- Delete: `src/config/hive.php`

**Interfaces:**
- Consumes: `HiveConnection` (Task 10), `HiveConnector` (Task 10)
- Produces: a registered `hive` driver resolvable via `DB::connection('hive')`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ServiceProviderTest.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Feature;

use Illuminate\Database\Connection;
use Sukhil\Database\Hive\Connectors\HiveConnector;
use Sukhil\Database\Hive\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_it_binds_the_hive_connector(): void
    {
        $this->assertTrue($this->app->bound('db.connector.hive'));
        $this->assertInstanceOf(HiveConnector::class, $this->app->make('db.connector.hive'));
    }

    public function test_it_registers_a_connection_resolver_for_hive(): void
    {
        $this->assertNotNull(Connection::getResolver('hive'));
    }

    public function test_it_merges_package_config_without_publishing(): void
    {
        $this->assertIsArray(config('hive.connections'));
        $this->assertArrayHasKey('hive', config('hive.connections'));
    }

    public function test_the_shipped_connection_declares_the_hive_driver(): void
    {
        // Before v7 the published config omitted this key, so the package's own
        // default connection could never be resolved as published.
        $this->assertSame('hive', config('hive.connections.hive.driver'));
    }

    public function test_application_config_takes_precedence_over_package_defaults(): void
    {
        $this->assertSame(
            'overridden',
            config('database.connections.hive.database')
        );
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.hive', [
            'driver' => 'hive',
            'dsn' => 'odbc:Driver=Fake;Host=localhost',
            'database' => 'overridden',
            'prefix' => '',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Feature/ServiceProviderTest.php`
Expected: FAIL — `db.connector.hive` is not bound.

- [ ] **Step 3: Create the published config**

`config/hive.php`:
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

- [ ] **Step 4: Rewrite the service provider**

`src/HiveServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use Sukhil\Database\Hive\Connectors\HiveConnector;

/**
 * Registers the Hive database driver with Laravel.
 */
class HiveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'hive');

        // ConnectionFactory::createConnector() resolves this binding by name.
        $this->app->bind('db.connector.hive', HiveConnector::class);

        // ConnectionFactory::createConnection() consults registered resolvers first.
        Connection::resolverFor(
            'hive',
            fn ($pdo, $database, $prefix, $config): HiveConnection
                => new HiveConnection($pdo, $database, $prefix, $config)
        );
    }

    public function boot(): void
    {
        $this->publishes([$this->configPath() => config_path('hive.php')], 'hive-config');

        $this->registerPackageConnections();
    }

    /**
     * Expose the package's own connection definitions to the database manager.
     *
     * Application-level config wins: connections already defined in
     * database.connections are left untouched. Runs in boot() because the
     * database manager resolves connections lazily, well after boot.
     */
    protected function registerPackageConnections(): void
    {
        $packageConnections = config('hive.connections');

        if (! is_array($packageConnections)) {
            return;
        }

        config([
            'database.connections' => array_merge(
                $packageConnections,
                (array) config('database.connections', [])
            ),
        ]);
    }

    protected function configPath(): string
    {
        return __DIR__ . '/../config/hive.php';
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return ['db.connector.hive'];
    }
}
```

- [ ] **Step 5: Delete the old config**

```bash
git rm src/config/hive.php
```

- [ ] **Step 6: Confirm no test is skipped**

Run: `docker compose run --rm php vendor/bin/phpunit --display-skipped`
Expected: zero skipped tests.

- [ ] **Step 7: Run the full suite**

Run: `docker compose run --rm php composer test`
Expected: PASS, all unit and feature tests.

- [ ] **Step 8: Commit**

```bash
git add src/HiveServiceProvider.php config/hive.php tests/Feature/
git rm --cached src/config/hive.php 2>/dev/null || true
git commit -m "feat: modernize service provider using resolverFor and db.connector binding"
```

---

### Task 12: Golden parity test

**Files:**
- Create: `tests/Unit/Schema/GoldenParityTest.php`

**Interfaces:**
- Consumes: `tests/fixtures/golden-v6-schema.json` (Task 2), `HiveSchemaGrammar` (Task 7), `HiveBlueprint` (Task 6)
- Produces: nothing consumed downstream.

- [ ] **Step 1: Write the parity test**

`tests/Unit/Schema/GoldenParityTest.php`:
```php
<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Sukhil\Database\Hive\Schema\Grammars\HiveSchemaGrammar;
use Sukhil\Database\Hive\Schema\HiveBlueprint;
use Sukhil\Database\Hive\Tests\Support\BlueprintFactory;

/**
 * Asserts the ported grammar reproduces the DDL that the pre-port grammar
 * emitted, except where a deviation is explicitly registered.
 */
final class GoldenParityTest extends TestCase
{
    /**
     * @return array<string, array<int, string>>
     */
    private function golden(): array
    {
        $path = __DIR__ . '/../../fixtures/golden-v6-schema.json';

        $this->assertFileExists($path, 'Run tools/capture-golden.sh first.');

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded, 'Golden fixture is empty; capture failed.');

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function deviations(): array
    {
        return require __DIR__ . '/../../fixtures/intentional-deviations.php';
    }

    /**
     * @return array<string, callable>
     */
    private function fixtures(): array
    {
        return [
            'numeric_types' => function (HiveBlueprint $table): void {
                $table->integer('integer_field');
                $table->bigInteger('big_integer');
                $table->smallInteger('small_integer');
                $table->tinyInteger('tinyinteger_field');
                $table->float('float_field');
                $table->double('double_field');
                $table->decimal('decimal_field');
            },
            'string_types' => function (HiveBlueprint $table): void {
                $table->string('string_field');
                $table->char('char_field');
                $table->text('text_field');
                $table->mediumText('medium_text_field');
                $table->longText('long_text_field');
            },
            'temporal_and_misc_types' => function (HiveBlueprint $table): void {
                $table->timestamp('timestamp_field');
                $table->date('date_field');
                $table->dateTime('datetime_field');
                $table->boolean('boolean_field');
                $table->binary('binary_field');
            },
            'modifiers_are_dropped' => function (HiveBlueprint $table): void {
                $table->string('nullable_field')->nullable();
                $table->integer('default_field')->default(7);
                $table->integer('unsigned_field')->unsigned();
            },
        ];
    }

    public function test_ported_grammar_matches_v6_output(): void
    {
        $golden = $this->golden();
        $deviations = $this->deviations();
        $compared = 0;

        foreach ($this->fixtures() as $name => $definition) {
            if (isset($deviations[$name])) {
                continue;
            }

            $this->assertArrayHasKey($name, $golden, "No golden entry for {$name}.");

            $connection = BlueprintFactory::connection();
            $grammar = new HiveSchemaGrammar($connection);
            $connection->setSchemaGrammar($grammar);

            $blueprint = BlueprintFactory::make('sample_table', $definition, $grammar);
            $blueprint->create();

            $actual = BlueprintFactory::toSql($blueprint, $connection, $grammar);

            $this->assertSame(
                $golden[$name],
                $actual,
                "Ported DDL for '{$name}' differs from v6. If deliberate, register it "
                . 'in tests/fixtures/intentional-deviations.php with a reason.'
            );

            $compared++;
        }

        $this->assertGreaterThan(0, $compared, 'No fixtures were compared.');
    }

    public function test_every_registered_deviation_has_a_reason(): void
    {
        foreach ($this->deviations() as $name => $reason) {
            $this->assertNotSame('', trim($reason), "Deviation '{$name}' has no reason.");
        }
    }
}
```

- [ ] **Step 2: Run the parity test**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Schema/GoldenParityTest.php`
Expected: PASS.

**If a fixture fails, do not immediately add it to the deviations file.** First determine whether the difference is a real porting error. The deviations register exists for reviewed, deliberate changes — using it to silence surprises defeats the purpose of the harness.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Schema/GoldenParityTest.php
git commit -m "test: assert ported DDL matches captured v6 output"
```

---

### Task 13: Full verification and cleanup

**Files:**
- Modify: none expected
- Verify: whole tree

- [ ] **Step 1: Confirm no stale classes remain**

Run:
```bash
docker compose run --rm php sh -c "! ls src/Schema/Builder.php src/config/hive.php src/Schema/Grammars/HiveGrammar.php src/Query/Grammars/HiveGrammar.php phpcs.xml 2>/dev/null"
```
Expected: exit 0 — none of these paths exist.

- [ ] **Step 2: Confirm strict types everywhere**

Run:
```bash
docker compose run --rm php sh -c 'for f in $(find src tests -name "*.php"); do grep -q "declare(strict_types=1)" "$f" || echo "MISSING: $f"; done'
```
Expected: no output.

- [ ] **Step 3: Confirm no PDO::quote reintroduced**

Run: `docker compose run --rm php grep -rn "getPdo()->quote\|->quote(" src/ || true`
Expected: no matches.

- [ ] **Step 4: Confirm version branching is confined**

Run: `docker compose run --rm php grep -rln "usesConnectionAwareSchemaApi" src/`
Expected: exactly three files — `Support/IlluminateVersion.php`, `HiveConnection.php`, `Schema/HiveSchemaBuilder.php`.

Run: `docker compose run --rm php grep -rn "method_exists(parent::class" src/`
Expected: exactly two matches, both inside `__construct` — `Query/Grammars/HiveQueryGrammar.php` and `Schema/Grammars/HiveSchemaGrammar.php`.

Any other occurrence of either pattern means version logic has leaked out of the four permitted sites.

- [ ] **Step 5: Run the full suite**

Run: `docker compose run --rm php composer test`
Expected: PASS, zero failures, zero warnings, zero risky tests.

- [ ] **Step 6: Verify against the other Laravel major**

`illuminate/database` lives in `require`, not `require-dev`, so `composer require --dev` would duplicate the constraint across both sections. Pin with `--no-update`, then resolve:

```bash
# --- Laravel 11 ---
docker compose run --rm php composer require --no-update --no-interaction \
  "illuminate/database:^11.0" "illuminate/support:^11.0"
docker compose run --rm php composer update --prefer-dist --no-interaction --no-blocking
docker compose run --rm php composer test
docker compose run --rm php php -r 'require "vendor/autoload.php"; echo \Illuminate\Foundation\Application::VERSION, PHP_EOL;'

# --- restore, then Laravel 12 ---
git checkout composer.json
docker compose run --rm php composer require --no-update --no-interaction \
  "illuminate/database:^12.0" "illuminate/support:^12.0"
docker compose run --rm php composer update --prefer-dist --no-interaction --no-blocking
docker compose run --rm php composer test
docker compose run --rm php php -r 'require "vendor/autoload.php"; echo \Illuminate\Foundation\Application::VERSION, PHP_EOL;'

# --- restore ---
git checkout composer.json
docker compose run --rm php composer update --prefer-dist --no-interaction
```

Expected: PASS under both, and the printed versions must actually differ — 11.x on the first run, 12.x on the second.

Two environment notes, both found the hard way:

- **Do not use `Composer\InstalledVersions::getVersion('illuminate/database')`** — it returns `NULL` under either major. Testbench pulls the `laravel/framework` monorepo, which `replace`s the split `illuminate/*` packages rather than installing them standalone. `Application::VERSION` is the reliable probe.
- **Laravel 11 needs `composer update --no-blocking`.** Composer 2.10+ refuses to install any release with a filed security advisory, and every Laravel 11.x release has one.

**This is the single most important verification step in the plan.** Every other test runs against whichever Laravel happened to install; this is the only proof the dual-version strategy works. If the printed version does not change between runs, the pin did not take and the step proved nothing — stop and report.

- [ ] **Step 7: Commit any fixes**

```bash
git add -A
git commit -m "test: verify suite passes against both Laravel 11 and 12"
```

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task |
|---|---|
| composer.json Laravel 11/12, PHP 8.2 | 1 |
| Docker: php always, hive opt-in, legacy-capture | 1, 2 |
| `IlluminateVersion` sole version seam | 3 |
| `HiveValueQuoter` replacing `PDO::quote()` | 4 |
| `HiveTableOptions` replacing dynamic properties | 5 |
| `HiveBlueprint` with no constructor override | 6 |
| `HiveSchemaGrammar` with widened `compileCreate` | 7 |
| `HiveSchemaBuilder` version branch | 8 |
| `HiveQueryGrammar` + `HiveProcessor` | 9 |
| `HiveConnection` no constructor, `HiveConnector` DSN | 10 |
| Provider via `resolverFor` + `db.connector.hive` | 11 |
| Config with `driver` key, moved to `config/` | 11 |
| Golden capture, schema only | 2, 12 |
| Deviations register | 2, 12 |
| Delete `phpcs.xml`, `src/config/` | 1, 11, 13 |

Phase 2 (Pint, PHPStan, CI) and Phase 3 (README, CHANGELOG, UPGRADE, docs/, community health files) are **not** covered here and require their own plans.

**Resolved during review:** an earlier draft had the grammar-construction logic backwards, assuming Laravel 12 exposed `setConnection()` and Laravel 11 needed the constructor. Both branches were checked and the truth is the reverse — Laravel 12 requires `__construct(Connection)` and has no setter; Laravel 11 has the setter and no constructor. Tasks 7, 9, 10 and 12 were corrected, and the asymmetry is documented inline at Task 10 Step 5 so an implementer does not "tidy" it back into a bug.

**Type consistency:** `usesConnectionAwareSchemaApi()` is used identically in Tasks 3, 8, 10, 13. `HiveValueQuoter::literal()` defined in Task 4, consumed in Task 9. `hiveOptions()` defined in Task 6, consumed in Task 7. `BlueprintFactory::make()`/`::connection()` defined in Task 6, consumed in Tasks 7, 9 and 12. Both grammar constructors take `?Connection` as their first parameter, and every construction site in Tasks 7, 9, 10 and 12 passes one.

**Residual risk:** `HiveValueQuoter`'s escaping is written to Hive's documented C-style string rules but has never been executed against a real Hive server. It is unambiguously better than `PDO::quote()`, which returns `false` under PDO_ODBC, but it is not empirically verified. This is the highest-value target for the opt-in `hive` Docker profile once an ODBC driver is available.
