# Phase 2: Quality Gates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Laravel Pint, PHPStan level 6 with zero suppressed errors, and GitHub Actions CI across the full supported matrix — plus the four behavioural defects deferred from Phase 1.

**Architecture:** Formatting lands first so its churn never collides with logic changes. Composer floors are corrected next, because CI's `prefer-lowest` job depends on them and they are currently wrong. PHPStan follows in two passes: mechanical generics, then the dual-version false positives that need judgement. Behavioural fixes come after, each with tests. CI lands last and consumes everything above.

**Tech Stack:** Laravel Pint ^1.18, PHPStan ^2.0 + larastan ^3.0, PHPUnit ^11.5, Orchestra Testbench ^9.5|^10.0, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-07-25-laravel-11-12-modernization-design.md`
**Phase 1 plan (context):** `docs/superpowers/plans/2026-07-25-phase-1-laravel-11-12-port.md`

## Global Constraints

- Branch is `feature/v7-laravel-11-12-port`. Stay on it; do not merge, push, or tag.
- PHP floor **8.2**. Laravel support **^11.0 || ^12.0**. Target release **v7.0.0**.
- **No local PHP or Composer.** Every command runs via `docker compose run --rm php <command>`.
- `timeout` is unavailable on this macOS host. Never use it.
- Every PHP file in `src/` and `tests/` begins with `declare(strict_types=1);`.
- `PDO::quote()` must never appear anywhere in the package.
- Version-dependent code stays confined to exactly four sites: `HiveConnection::configureGrammar`, `HiveSchemaBuilder::createBlueprint`, `HiveQueryGrammar::__construct`, `HiveSchemaGrammar::__construct` — plus `Support/IlluminateVersion` itself. **Do not add a fifth.**
- `phpunit.xml` sets `failOnWarning`, `failOnRisky`, `failOnDeprecation`, `failOnNotice`, `failOnPhpunitDeprecation`. Every run must end in a bare `OK (...)`.
- **Never modify `tests/fixtures/golden-v6-schema.json`.** If golden parity breaks, that is a finding to report, not a fixture to edit.
- Do not commit `composer.lock` — this is a library.
- Leave untracked `CLAUDE.md`, `docs/`, `.claude/` alone. Never commit from `.superpowers/`.
- Add **new commits**; never amend a commit that has been reviewed.
- Baseline at plan start: **121 tests, 242 assertions**, Laravel 12.64.0.

## Out of Scope (Phase 3)

README, CHANGELOG, UPGRADE.md, `docs/` guides, `LICENSE.md` (currently 0 bytes), CONTRIBUTING, CODE_OF_CONDUCT, SECURITY, issue/PR templates, `.gitattributes`. Several Phase 1 findings defer their user-facing documentation to Phase 3 and must **not** be documented here: the `[A-Za-z0-9_]` identifier restriction, the SerDe-wins-over-delimiter silent precedence, CREATE-only schema support, and `statement()` discarding bindings.

---

## File Structure

**Created:**

| Path | Responsibility |
|---|---|
| `pint.json` | Pins the Pint preset so formatting is reproducible |
| `phpstan.neon` | PHPStan level 6 config with larastan extension |
| `.github/workflows/ci.yml` | Test matrix, lint, static analysis, coverage artifact |

**Modified:** `composer.json` (dev floors + scripts), `src/Support/HiveIdentifier.php`, `src/Query/Grammars/HiveQueryGrammar.php`, `src/Schema/Grammars/HiveSchemaGrammar.php`, `src/Schema/HiveSchemaBuilder.php`, `src/HiveServiceProvider.php`, `src/Connectors/HiveConnector.php`, plus most files under `tests/` (formatting only), and `tests/Unit/Support/HiveIdentifierTest.php`, `tests/Unit/Query/HiveQueryGrammarTest.php`, `tests/Feature/ServiceProviderTest.php`, `tests/Unit/Connectors/HiveConnectorTest.php` (new assertions).

---

### Task 1: Pint configuration and formatting pass

Formatting first, so its churn never mixes with logic diffs in later review.

**Files:**
- Create: `pint.json`
- Modify: 13 files across `src/`, `tests/`, `tools/`, `tests/fixtures/` (auto-formatted)

**Interfaces:**
- Consumes: nothing
- Produces: `composer lint` (check-only, used by CI in Task 7) and `composer fix` (writes). Both already declared in `composer.json`'s `scripts` block from Phase 1.

- [ ] **Step 1: Confirm the current violations**

Run: `docker compose run --rm php vendor/bin/pint --test`
Expected: FAIL listing 13 files with `concat_space`, `new_with_parentheses`, `single_quote`, `fully_qualified_strict_types`, `single_line_empty_body`.

- [ ] **Step 2: Create `pint.json`**

The Laravel preset's defaults are what produced the violation list above, so no rule overrides are needed — this file exists to pin the preset explicitly so a future Pint default change cannot silently reformat the package.

```json
{
    "preset": "laravel"
}
```

- [ ] **Step 3: Apply the formatting**

Run: `docker compose run --rm php vendor/bin/pint`
Expected: reports the same 13 files as FIXED.

- [ ] **Step 4: Verify the check-only script is now clean**

Run: `docker compose run --rm php composer lint`
Expected: PASS, no files listed.

- [ ] **Step 5: Verify the test suite still passes**

Run: `docker compose run --rm php composer test`
Expected: `OK (121 tests, 242 assertions)` — bare `OK`. Formatting must not change behaviour; any change in counts means something else moved.

- [ ] **Step 6: Verify the golden-capture harness still runs under PHP 8.0**

This is the non-obvious risk in this task. `tools/capture-golden.php` is executed by the `legacy-capture` container on **PHP 8.0**, not 8.3. Pint is configured for this package's PHP 8.2 floor, so a reformat there could in principle emit syntax PHP 8.0 rejects.

Run:
```bash
docker compose --profile capture run --rm legacy-capture sh /app/tools/capture-golden.sh
```
Expected: `Captured 5 fixtures.`

Then confirm the fixture is byte-identical:

Run: `git diff --stat tests/fixtures/golden-v6-schema.json`
Expected: no output.

**If the capture errors with a parse error, Pint has emitted PHP 8.1+ syntax into a file that must stay 8.0-compatible.** Do not downgrade the whole preset — add `tools/` to an `exclude` array in `pint.json` and re-run, and say so in your report.

- [ ] **Step 7: Verify golden parity still passes**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Schema/GoldenParityTest.php`
Expected: `OK (4 tests, 25 assertions)`

- [ ] **Step 8: Commit**

```bash
git add pint.json src tests tools
git commit -m "style: add Pint config and apply Laravel preset formatting"
```

---

### Task 2: Correct the Composer dev floors

**The current floors are wrong and `prefer-lowest` fails against them.** This task must land before CI (Task 7), which runs that job.

**Files:**
- Modify: `composer.json`

**Interfaces:**
- Consumes: nothing
- Produces: a `composer.json` whose declared minimums actually install and pass. Task 7's CI matrix depends on this.

**Background — verified, do not re-derive.** With the Phase 1 floors (`orchestra/testbench: ^9.0 || ^10.0`, `phpunit/phpunit: ^10.5 || ^11.0`), `composer update --prefer-lowest` resolves Testbench 9.0.0 + PHPUnit 10.5.0 and produces **6 errors plus 1 warning**:

- All six `tests/Feature/ServiceProviderTest` cases die with `Error: Access to undeclared static property ...::$latestResponse` at `vendor/orchestra/testbench-core/src/TestCase.php:49` — a Testbench 9.0.x defect.
- `phpunit.xml` fails schema validation: `Element 'phpunit', attribute 'failOnPhpunitDeprecation': The attribute 'failOnPhpunitDeprecation' is not allowed.` That attribute does not exist before PHPUnit 11.5 (confirmed absent in 11.0.1, 11.1.0 and 11.3.0).

The verified working minimums are **Testbench 9.5.0** and **PHPUnit 11.5.0**.

- [ ] **Step 1: Reproduce the failure**

Run:
```bash
docker compose run --rm php composer update --prefer-dist --prefer-lowest --no-interaction --no-blocking
docker compose run --rm php composer test
```
Expected: `Tests: 121, Assertions: 231, Errors: 6, Warnings: 1.`

`--no-blocking` is required: Composer 2.10+ refuses to install any release carrying a security advisory, and every Laravel 11.x release has one.

- [ ] **Step 2: Raise the floors in `composer.json`**

In the `require-dev` block, change these two entries only:

```json
"orchestra/testbench": "^9.5 || ^10.0",
"phpunit/phpunit": "^11.5",
```

Note `^10.5` is dropped from PHPUnit entirely — the config this package ships cannot be validated by any PHPUnit 10.x.

- [ ] **Step 3: Verify `prefer-lowest` now passes**

Run:
```bash
docker compose run --rm php composer update --prefer-dist --prefer-lowest --no-interaction --no-blocking
docker compose run --rm php composer show --direct | grep -iE "phpunit/phpunit|testbench"
docker compose run --rm php php -r 'require "vendor/autoload.php"; echo \Illuminate\Foundation\Application::VERSION, PHP_EOL;'
docker compose run --rm php composer test
```
Expected: Testbench `9.5.0`, PHPUnit `11.5.0`, framework `11.33.2`, and `OK (121 tests, 239 assertions)`.

The assertion count is **239 here, not 242** — three assertions are version-conditional. That difference is expected; a count of 242 under Laravel 11 would mean the pin did not take.

- [ ] **Step 4: Restore the newest resolution**

Run:
```bash
rm -f composer.lock
docker compose run --rm php composer update --prefer-dist --no-interaction
docker compose run --rm php php -r 'require "vendor/autoload.php"; echo \Illuminate\Foundation\Application::VERSION, PHP_EOL;'
docker compose run --rm php composer test
```
Expected: framework `12.64.0`, `OK (121 tests, 242 assertions)`.

Deleting the lock is necessary — a plain `composer update` can be satisfied by the existing lock and silently leave you on Laravel 11.

- [ ] **Step 5: Commit**

```bash
git add composer.json
git commit -m "build: raise testbench and phpunit floors to versions that actually pass"
```

---

### Task 3: PHPStan config and the mechanical generic annotations

**Files:**
- Create: `phpstan.neon`
- Modify: `src/Schema/Grammars/HiveSchemaGrammar.php`, `composer.json`

**Interfaces:**
- Consumes: nothing
- Produces: `composer analyse`, used by CI in Task 7.

**Background.** A level-6 probe reports **48 errors** across `src/` and `tests/`. Twenty-three are in `HiveSchemaGrammar` and are all the same shape: `missingType.generics` — `Illuminate\Support\Fluent` is generic (`Fluent<TKey of array-key, TValue>`) and the `type*` methods take it unparameterised. This task clears those; Task 4 handles the rest.

PHPStan needs more than the default memory: the probe crashed at 128M. Use `--memory-limit=1G`.

- [ ] **Step 1: Create `phpstan.neon`**

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 6

    paths:
        - src
        - tests

    tmpDir: build/phpstan
```

- [ ] **Step 2: Add the memory limit to the composer script**

In `composer.json`'s `scripts` block, replace the `analyse` entry:

```json
"analyse": "phpstan analyse --memory-limit=1G",
```

- [ ] **Step 3: Confirm the starting error count**

Run: `docker compose run --rm php composer analyse`
Expected: FAIL, `[ERROR] Found 48 errors`.

- [ ] **Step 4: Annotate every `Fluent` parameter in the schema grammar**

`src/Schema/Grammars/HiveSchemaGrammar.php` has one `type*` method per Hive type, each taking `Fluent $column`. Add a generic `@param` to each. The pattern, applied to `typeChar` as the example:

```php
    /**
     * CHAR is fixed-length; shorter values are space-padded. Maximum 255.
     *
     * @param  Fluent<string, mixed>  $column
     */
    protected function typeChar(Fluent $column): string
    {
        return "char({$column->get('length')})";
    }
```

Apply the same `@param  Fluent<string, mixed>  $column` line to **every** method in the file that takes a `Fluent`, preserving each method's existing description. At the time of writing these are: `typeChar`, `typeString`, `typeVarChar`, `typeText`, `typeMediumText`, `typeLongText`, `typeBigInteger`, `typeInteger`, `typeMediumInteger`, `typeTinyInteger`, `typeSmallInteger`, `typeNumeric`, `typeFloat`, `typeDouble`, `typeDecimal`, `typeBoolean`, `typeDate`, `typeDateTime`, `typeTimestamp`, `typeBinary`. Let PHPStan tell you if you missed one rather than trusting this list.

`compileCreate` also takes a `Fluent $command` — annotate it `@param  Fluent<string, mixed>  $command` alongside its existing params.

- [ ] **Step 5: Confirm the count dropped**

Run: `docker compose run --rm php composer analyse`
Expected: FAIL, but with roughly **25 errors** remaining — all outside `HiveSchemaGrammar`. If any `missingType.generics` error still names this file, a method was missed.

- [ ] **Step 6: Verify tests and formatting still pass**

Run:
```bash
docker compose run --rm php composer test
docker compose run --rm php composer lint
```
Expected: `OK (121 tests, 242 assertions)` and a clean lint. Docblock-only edits must not change behaviour.

- [ ] **Step 7: Commit**

```bash
git add phpstan.neon composer.json src/Schema/Grammars/HiveSchemaGrammar.php
git commit -m "build: add PHPStan level 6 config and annotate Fluent generics"
```

---

### Task 4: Resolve the remaining PHPStan errors

**Files:**
- Modify: `src/Schema/HiveSchemaBuilder.php`, `src/Query/Grammars/HiveQueryGrammar.php`, and whichever `tests/` files still report errors

**Interfaces:**
- Consumes: `phpstan.neon` and `composer analyse` (Task 3)
- Produces: a clean `composer analyse` run. CI (Task 7) will fail the build on any regression.

**Background — these need judgement, not mechanical edits.** Six errors cluster in `HiveSchemaBuilder::createBlueprint`, and they are a **cascade from one root cause**. PHPStan sees `Illuminate\Database\Schema\Builder::$resolver` declared as a non-nullable `Closure`, so it concludes `isset($this->resolver)` is always true:

```
51  Property Illuminate\Database\Schema\Builder::$resolver (Closure) in isset() is not nullable.   isset.property
66  Parameter #1 of callable passed to call_user_func() expects Connection, string given.          argument.type
66  Parameter #2 of callable passed to call_user_func() expects string, Closure|null given.        argument.type
69  Unreachable statement - code above always terminates.                                          deadCode.unreachable
71  No error to ignore is reported on line 71.                                       ignore.unmatchedLine
75  No error to ignore is reported on line 75.                                       ignore.unmatchedLine
```

**The code is correct.** The property is genuinely uninitialised until `blueprintResolver()` is called, and the two `argument.type` errors are PHPStan reading the Laravel 12 resolver signature from the property's docblock while looking at the Laravel 11 branch — which deliberately calls it with the Laravel 11 shape. The "unreachable" and "no error to ignore" reports are downstream of the bad `isset` inference; they should vanish once it is corrected.

**Do not** silence these with a baseline, and **do not** restructure `createBlueprint` to please the analyser — its shape is load-bearing for dual-version support.

- [ ] **Step 1: List what remains**

Run: `docker compose run --rm php composer analyse`
Expected: roughly 25 errors. Read them all before editing; several `tests/` errors will be simple missing array-shape or generic annotations.

- [ ] **Step 2: Correct the `isset` inference at its root**

Add a targeted ignore with an explanation, immediately above the `isset` in `src/Schema/HiveSchemaBuilder.php`:

```php
        // Illuminate types $resolver as a non-nullable Closure, so PHPStan
        // believes it is always set. It is genuinely uninitialised until
        // blueprintResolver() is called, which is exactly what this guards.
        /** @phpstan-ignore isset.property */
        if (isset($this->resolver)) {
```

- [ ] **Step 3: Re-run and confirm the cascade collapsed**

Run: `docker compose run --rm php composer analyse`
Expected: the `deadCode.unreachable` error on line 69 and both `ignore.unmatchedLine` errors are gone, because PHPStan now analyses the code after the `isset` block.

**If the two `ignore.unmatchedLine` errors persist**, the existing `@phpstan-ignore-next-line` comments on the `new HiveBlueprint(...)` lines are genuinely unmatched — meaning PHPStan no longer objects to those constructor calls. In that case delete those two comments rather than keeping dead suppressions.

- [ ] **Step 4: Annotate the version-divergent resolver call**

The two `argument.type` errors on the Laravel 11 branch remain, because PHPStan reads the Laravel 12 signature. Add:

```php
            // PHPStan reads Illuminate's Laravel 12 resolver signature
            // ($connection, $table, $callback) from the property docblock. This
            // branch only runs on Laravel 11, whose Builder calls the resolver
            // as ($table, $callback, $prefix) — verified against 11.x source.
            /** @phpstan-ignore argument.type, argument.type */
            return call_user_func($this->resolver, $table, $callback, $prefix);
```

- [ ] **Step 5: Resolve every remaining error**

Work through what is left. Most will be `missingType.iterableValue` or `missingType.generics` in `tests/` — fix those with real annotations (`array<string, mixed>`, `Fluent<string, mixed>`), not ignores.

**Rule for this step: an ignore requires a comment saying why the analyser is wrong.** If you cannot write that sentence honestly, the analyser is probably right and the code should change instead. Report any error where you concluded the analyser was right and you changed behaviour.

- [ ] **Step 6: Confirm a clean run**

Run: `docker compose run --rm php composer analyse`
Expected: `[OK] No errors`

- [ ] **Step 7: Verify nothing else moved**

Run:
```bash
docker compose run --rm php composer test
docker compose run --rm php composer lint
docker compose run --rm php vendor/bin/phpunit tests/Unit/Schema/GoldenParityTest.php
```
Expected: `OK (121 tests, 242 assertions)`, clean lint, `OK (4 tests, 25 assertions)`.

- [ ] **Step 8: Commit**

```bash
git add src tests
git commit -m "build: resolve remaining PHPStan level 6 errors without a baseline"
```

---

### Task 5: Fix the dotted-prefix rejection and the empty-identifier bypass

Two defects in identifier validation, found by the Phase 1 final review.

**Files:**
- Modify: `src/Support/HiveIdentifier.php`, `src/Query/Grammars/HiveQueryGrammar.php`, `src/Schema/Grammars/HiveSchemaGrammar.php`
- Test: `tests/Unit/Support/HiveIdentifierTest.php`, `tests/Unit/Query/HiveQueryGrammarTest.php`

**Interfaces:**
- Consumes: `HiveIdentifier::assertSafe(string $value): string` (Phase 1)
- Produces: `HiveIdentifier::assertSafeQualified(string $value): string` — validates a possibly dot-qualified name segment by segment, returning it unchanged or throwing `InvalidArgumentException`.

**Background.** `HiveQueryGrammar::wrapTable()` currently concatenates the prefix and validates the result as a single identifier:

```php
return HiveIdentifier::assertSafe($prefix . $table);
```

So a dotted prefix — `'prefix' => 'analytics.'` — throws `Unsafe Hive identifier 'analytics.events'`, even though `DB::table('analytics.events')` compiles fine, because the dot branch above it validates per segment. Before Phase 1's security fix, the schema grammar emitted `create table analytics.events` happily, so this is a regression against v6.

Separately, `wrapTable('')` throws when no prefix is set but returns a bare `pfx_` when one is — the empty check is bypassed by concatenation.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Support/HiveIdentifierTest.php`:

```php
    public function test_assert_safe_qualified_accepts_a_dotted_name(): void
    {
        $this->assertSame(
            'analytics.events',
            HiveIdentifier::assertSafeQualified('analytics.events')
        );
    }

    public function test_assert_safe_qualified_accepts_a_plain_name(): void
    {
        $this->assertSame('events', HiveIdentifier::assertSafeQualified('events'));
    }

    public function test_assert_safe_qualified_rejects_an_unsafe_segment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'ev ents'");

        HiveIdentifier::assertSafeQualified('analytics.ev ents');
    }

    public function test_assert_safe_qualified_rejects_an_empty_segment(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HiveIdentifier::assertSafeQualified('analytics..events');
    }

    public function test_assert_safe_qualified_rejects_an_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HiveIdentifier::assertSafeQualified('');
    }
```

Add to `tests/Unit/Query/HiveQueryGrammarTest.php`, alongside the existing prefixed-builder tests:

```php
    public function test_it_accepts_a_dotted_table_prefix(): void
    {
        $connection = BlueprintFactory::connection();
        $connection->setTablePrefix('analytics.');
        $grammar = new HiveQueryGrammar($connection);

        $this->assertSame('analytics.events', $grammar->wrapTable('events'));
    }

    public function test_it_rejects_an_empty_table_name_even_with_a_prefix(): void
    {
        $connection = BlueprintFactory::connection();
        $connection->setTablePrefix('pfx_');
        $grammar = new HiveQueryGrammar($connection);

        $this->expectException(InvalidArgumentException::class);

        $grammar->wrapTable('');
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Support/HiveIdentifierTest.php tests/Unit/Query/HiveQueryGrammarTest.php`
Expected: FAIL — `Call to undefined method ...HiveIdentifier::assertSafeQualified()` for the first group, and for the second an `InvalidArgumentException` about `'analytics.events'` where none was expected, plus a missing exception on the empty-table case.

- [ ] **Step 3: Add `assertSafeQualified` to `HiveIdentifier`**

```php
    /**
     * Validate a possibly schema-qualified name, one dot-separated segment at
     * a time.
     *
     * A dot is legal *between* Hive identifiers but not *within* one, so
     * `analytics.events` is fine while `ev ents` is not. Validating the whole
     * string with assertSafe() would reject every qualified name — and, once a
     * dotted table prefix is configured, every table.
     *
     * @throws InvalidArgumentException
     */
    public static function assertSafeQualified(string $value): string
    {
        if ($value === '') {
            throw new InvalidArgumentException(
                'Unsafe Hive identifier: the name is empty.'
            );
        }

        foreach (explode('.', $value) as $segment) {
            self::assertSafe($segment);
        }

        return $value;
    }
```

`assertSafe` already rejects the empty string, so `analytics..events` fails on its middle segment without a special case.

- [ ] **Step 4: Use it in `HiveQueryGrammar::wrapTable`**

Replace the two prefix-concatenating call sites. The alias branch:

```php
                return $this->wrapTable($segments[0], $prefix)
                    . ' as ' . HiveIdentifier::assertSafeQualified($prefix . $segments[1]);
```

The dotted branch's per-segment map can now delegate to the same helper — replace the whole `implode`/`array_map` block with:

```php
            return HiveIdentifier::assertSafeQualified($qualified);
```

And the plain branch, which must reject an empty table name **before** the prefix can mask it:

```php
        if ($table === '') {
            throw new InvalidArgumentException(
                'Unsafe Hive identifier: the table name is empty.'
            );
        }

        return HiveIdentifier::assertSafeQualified($prefix . $table);
```

Ensure `InvalidArgumentException` is imported in the grammar.

- [ ] **Step 5: Apply the same helper in the schema grammar**

`src/Schema/Grammars/HiveSchemaGrammar.php` validates identifiers in `wrapValue()`. A dotted prefix reaches DDL through the inherited `wrapTable()`, so change its `HiveIdentifier::assertSafe(...)` call to `HiveIdentifier::assertSafeQualified(...)` **only where a qualified name can legitimately arrive** — column names cannot be dotted, so `wrapValue()` keeps `assertSafe`. Read the file and decide; state your reasoning in the report.

- [ ] **Step 6: Run the tests**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Support/HiveIdentifierTest.php tests/Unit/Query/HiveQueryGrammarTest.php`
Expected: PASS.

- [ ] **Step 7: Verify the security fix did not regress**

The whole point of this validation is blocking injection. Confirm it still does:

```bash
docker compose run --rm php php -r '
require "vendor/autoload.php";
use Sukhil\Database\Hive\Query\Grammars\HiveQueryGrammar;
use Sukhil\Database\Hive\Tests\Support\BlueprintFactory;
use Illuminate\Database\Query\Builder;
$c = BlueprintFactory::connection(); $g = new HiveQueryGrammar($c);
$q = new Builder($c, $g); $q->from = "events";
foreach (["name) values (@@x --", "events\n", "a.b) --", "x;drop table y"] as $k) {
  try { $g->compileInsert($q, [$k => "v"]); echo "NOT BLOCKED: ", var_export($k, true), "\n"; }
  catch (InvalidArgumentException $e) { echo "blocked: ", var_export($k, true), "\n"; }
}'
```
Expected: all four `blocked`. **If any prints NOT BLOCKED, stop and report** — `assertSafeQualified` has widened the accepted set too far.

- [ ] **Step 8: Full verification**

Run:
```bash
docker compose run --rm php composer test
docker compose run --rm php composer lint
docker compose run --rm php composer analyse
docker compose run --rm php vendor/bin/phpunit tests/Unit/Schema/GoldenParityTest.php
```
Expected: bare `OK` with 7 more tests than the 121 baseline, clean lint, `[OK] No errors`, and golden parity `OK (4 tests, 25 assertions)`.

- [ ] **Step 9: Commit**

```bash
git add src tests
git commit -m "fix: accept dotted table prefixes and reject empty table names"
```

---

### Task 6: Remove the inert `provides()` and fail loudly on a missing DSN

Two more Phase 1 deferrals, both small and independent of Task 5.

**Files:**
- Modify: `src/HiveServiceProvider.php`, `src/Connectors/HiveConnector.php`
- Test: `tests/Feature/ServiceProviderTest.php`, `tests/Unit/Connectors/HiveConnectorTest.php`

**Interfaces:**
- Consumes: `HiveConnector::getDsn(array $config): ?string` (Phase 1, protected)
- Produces: `HiveConnector::connect()` throwing `InvalidArgumentException` on a missing or empty DSN.

**Background on `provides()` — read before deciding.** `HiveServiceProvider::provides()` returns `['db.connector.hive']`, but Laravel only consults `provides()` on providers implementing `Illuminate\Contracts\Support\DeferrableProvider`. This one does not, so the method is dead.

**The fix is to delete it, not to implement the contract.** Deferring this provider would break it two ways:

1. `register()` calls `Connection::resolverFor('hive', ...)` as a side effect. `ConnectionFactory::createConnection()` consults that resolver, but the connector binding is only resolved later, inside a lazy PDO closure — so the connection could be built before anything ever requested `db.connector.hive`, meaning the resolver would not yet be registered.
2. `boot()` merges `hive.connections` into `database.connections`. Deferred providers do not boot until resolved, so the package's own connection definitions would silently vanish.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ServiceProviderTest.php`:

```php
    public function test_the_provider_is_not_deferred(): void
    {
        // register() registers a connection resolver as a side effect and
        // boot() merges the package's connections; deferring either would
        // break connection resolution silently.
        $this->assertNotInstanceOf(
            \Illuminate\Contracts\Support\DeferrableProvider::class,
            new HiveServiceProvider($this->app)
        );

        $this->assertFalse(
            method_exists(HiveServiceProvider::class, 'provides'),
            'provides() is only consulted for deferred providers; leaving it invites false confidence.'
        );
    }
```

Add to `tests/Unit/Connectors/HiveConnectorTest.php`:

```php
    public function test_connect_rejects_a_missing_dsn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Hive DSN');

        (new HiveConnector())->connect(['database' => 'default']);
    }

    public function test_connect_rejects_an_empty_dsn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Hive DSN');

        (new HiveConnector())->connect(['dsn' => '', 'database' => 'default']);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Feature/ServiceProviderTest.php tests/Unit/Connectors/HiveConnectorTest.php`
Expected: FAIL — `provides()` still exists, and `connect()` raises a `PDOException` about an invalid DSN rather than the intended `InvalidArgumentException`.

- [ ] **Step 3: Delete `provides()`**

Remove the whole method, and its docblock, from `src/HiveServiceProvider.php`. Do not implement `DeferrableProvider`.

- [ ] **Step 4: Make a missing DSN fail loudly**

In `src/Connectors/HiveConnector.php`, replace the body of `connect()`:

```php
    /**
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidArgumentException
     */
    public function connect(array $config): PDO
    {
        $dsn = $this->getDsn($config);

        if ($dsn === null) {
            throw new InvalidArgumentException(
                'Hive DSN is not configured. Set the "dsn" key on the connection '
                . '(or the HIVE_DSN environment variable) to an ODBC DSN.'
            );
        }

        return $this->createConnection($dsn, $config, $this->getOptions($config));
    }
```

Import `InvalidArgumentException`. Leave `getDsn()` returning `?string` — its null result is now meaningful rather than silently cast.

- [ ] **Step 5: Run the tests**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Feature/ServiceProviderTest.php tests/Unit/Connectors/HiveConnectorTest.php`
Expected: PASS.

- [ ] **Step 6: Full verification**

Run:
```bash
docker compose run --rm php composer test
docker compose run --rm php composer lint
docker compose run --rm php composer analyse
```
Expected: bare `OK`, clean lint, `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
git add src tests
git commit -m "fix: drop inert provides() and reject an unconfigured Hive DSN"
```

---

### Task 7: GitHub Actions CI

**Files:**
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `composer test`, `composer lint`, `composer analyse` (Tasks 1, 3), and the corrected floors (Task 2)
- Produces: nothing downstream — this is the last task in Phase 2.

**Background.** Both Laravel 11 and 12 require PHP 8.2+, so the matrix needs no exclusions. Two things are non-obvious and were established by hand:

- **`--no-blocking` is mandatory** on any `composer update` that resolves Laravel 11: Composer 2.10+ refuses to install releases carrying a security advisory, and every 11.x release has one. The flag requires Composer 2.10+, so the workflow pins it explicitly.
- **`prefer-lowest` only passes with the Task 2 floors.** If Task 2 has not landed, this job fails with six Testbench errors and a PHPUnit schema warning.

- [ ] **Step 1: Create the workflow**

`.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
    branches: [master, 'feature/**']
  pull_request:

jobs:
  tests:
    name: PHP ${{ matrix.php }} · Laravel ${{ matrix.laravel }} · ${{ matrix.deps }}
    runs-on: ubuntu-latest

    strategy:
      fail-fast: false
      matrix:
        php: ['8.2', '8.3', '8.4']
        laravel: ['11', '12']
        deps: [prefer-lowest, prefer-stable]

    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: pdo, pdo_sqlite, odbc
          tools: composer:2.10
          coverage: none

      - name: Pin Laravel ${{ matrix.laravel }}
        run: |
          composer require --no-update --no-interaction \
            "illuminate/database:^${{ matrix.laravel }}.0" \
            "illuminate/support:^${{ matrix.laravel }}.0"

      - name: Install dependencies
        run: composer update --prefer-dist --no-interaction --no-blocking --${{ matrix.deps }}

      - name: Report resolved versions
        run: |
          php -r 'require "vendor/autoload.php"; echo "framework ", \Illuminate\Foundation\Application::VERSION, PHP_EOL;'
          composer show --direct

      - name: Run tests
        run: composer test

  quality:
    name: Lint and static analysis
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo, pdo_sqlite, odbc
          tools: composer:2.10
          coverage: none

      - name: Install dependencies
        run: composer update --prefer-dist --no-interaction

      - name: Check formatting
        run: composer lint

      - name: Static analysis
        run: composer analyse

  coverage:
    name: Coverage report
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo, pdo_sqlite, odbc
          tools: composer:2.10
          coverage: pcov

      - name: Install dependencies
        run: composer update --prefer-dist --no-interaction

      - name: Generate coverage
        run: vendor/bin/phpunit --coverage-clover build/coverage/clover.xml --coverage-html build/coverage/html

      - name: Upload coverage artifact
        uses: actions/upload-artifact@v4
        with:
          name: coverage
          path: build/coverage
          retention-days: 14
```

`extensions: odbc` installs the ODBC extension but **no Hive driver** — nothing in the suite opens a connection, so this only satisfies the `ext-odbc` suggestion. Do not add a Hive ODBC driver step; none is redistributable.

- [ ] **Step 2: Validate the workflow parses**

Run:
```bash
docker compose run --rm php php -r '
$y = file_get_contents("/app/.github/workflows/ci.yml");
echo substr_count($y, "runs-on"), " jobs declared", PHP_EOL;'
```
Expected: `3 jobs declared`.

If `gh` is available and authenticated, `gh workflow view` after pushing is the real check — but pushing is out of scope for this plan, so the syntax check above plus review is the gate here.

- [ ] **Step 3: Verify the matrix commands work locally**

CI cannot run here, but every command it invokes can. Confirm the exact pin-then-update sequence the workflow uses:

```bash
docker compose run --rm php composer require --no-update --no-interaction "illuminate/database:^11.0" "illuminate/support:^11.0"
docker compose run --rm php composer update --prefer-dist --no-interaction --no-blocking --prefer-lowest
docker compose run --rm php composer test
```
Expected: `OK (121 tests, 239 assertions)`.

Then restore:

```bash
git checkout composer.json
rm -f composer.lock
docker compose run --rm php composer update --prefer-dist --no-interaction
docker compose run --rm php composer test
```
Expected: framework `12.64.0`, `OK` with the full assertion count.

- [ ] **Step 4: Verify coverage generation works**

The `coverage` job uses pcov, which is not installed in the local image. Confirm the command shape is right by running it without a coverage driver:

Run: `docker compose run --rm php vendor/bin/phpunit --coverage-clover build/coverage/clover.xml 2>&1 | tail -3`
Expected: a message about no code coverage driver being available — **not** an argument or configuration error. An "unknown option" error means the flag is wrong.

Then: `rm -rf build/coverage`

- [ ] **Step 5: Confirm `build/` is ignored**

Run: `git status --short`
Expected: no `build/` entry. `.gitignore` already carries `/build` from Phase 1; if `build/` appears, add it before committing.

- [ ] **Step 6: Final full verification**

Run:
```bash
docker compose run --rm php composer test
docker compose run --rm php composer lint
docker compose run --rm php composer analyse
docker compose run --rm php vendor/bin/phpunit tests/Unit/Schema/GoldenParityTest.php
git status --short
```
Expected: bare `OK`, clean lint, `[OK] No errors`, golden parity green, and a working tree showing only the expected untracked `.claude/`, `CLAUDE.md`, `docs/`.

- [ ] **Step 7: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: add GitHub Actions matrix, quality gates and coverage artifact"
```

---

## Self-Review

**Spec coverage:**

| Spec requirement (Phase 2) | Task |
|---|---|
| Laravel Pint replacing `phpcs.xml` | 1 (`phpcs.xml` already deleted in Phase 1 Task 1) |
| PHPStan level 6, no baseline | 3, 4 |
| `composer test` / `lint` / `analyse` scripts | 1, 3 (scripts declared in Phase 1) |
| CI matrix PHP 8.2–8.4 × Laravel 11–12 × lowest/stable | 7 |
| `prefer-lowest` catching under-specified constraints | 2 (it did — floors were wrong) |
| Coverage reporting | 7 (artifact, per the ruling) |
| Deferred behavioural minors | 5, 6 |

Deferred **documentation** minors are deliberately absent — they belong to Phase 3, listed under Out of Scope above.

**Placeholder scan:** no TBDs, no "add appropriate error handling", every code step carries real code. Task 3 Step 4 lists the twenty `type*` methods by name but tells the implementer to trust PHPStan over the list, which is a verification instruction rather than a placeholder. Task 5 Step 5 asks the implementer to read one file and decide where a qualified name can arrive — that is a genuine judgement call with a stated decision procedure and a reporting requirement, not an unfilled blank.

**Type consistency:** `HiveIdentifier::assertSafe(string): string` (Phase 1) and `assertSafeQualified(string): string` (Task 5) are used identically in Tasks 5 and 4. `HiveConnector::getDsn(array): ?string` keeps its Phase 1 signature in Task 6; only `connect()` changes. `composer lint` / `analyse` / `test` are named consistently in Tasks 1, 3, 4, 5, 6, 7.

**Known risks:**

1. **Task 1 Step 6** is the one place formatting could break something non-obvious — `tools/capture-golden.php` must stay PHP 8.0-parseable. A mitigation is written into the step.
2. **Task 4 cannot be fully specified in advance.** Roughly 25 errors remain after Task 3, and only their broad shapes are known. The step gives a decision rule (an ignore requires a written justification) rather than per-error instructions, because enumerating them would mean guessing.
3. **The CI workflow is unverifiable locally.** Task 7 Steps 2–4 check what can be checked — command shapes, flag validity, job count — but the workflow's first real run happens on push, which this plan does not perform.
