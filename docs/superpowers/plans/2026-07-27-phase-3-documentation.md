# Phase 3: Documentation and Repository Standards Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the package a real licence, honest documentation of what it can and cannot do, a professional README, an upgrade path from v6, and the community-health files an open-source repository is expected to carry.

**Architecture:** Packaging and licence first, because they are legal facts the rest references. Then the reference docs, because the README links into them. Then the README, changelog and upgrade guide, which describe what the docs establish. Community files last — they depend on nothing.

**Tech Stack:** Markdown, GitHub community-health conventions, Keep a Changelog 1.1.0, Contributor Covenant 2.1.

**Spec:** `docs/superpowers/specs/2026-07-25-laravel-11-12-modernization-design.md`

## Global Constraints

- Branch is `feature/v7-laravel-11-12-port`. Stay on it; do not merge, push, or tag.
- Target release is **v7.0.0**. Supported: **PHP 8.2+**, **Laravel 11.x and 12.x**.
- Package name `sukhilss/laravel-odbc-hive`; repository `https://github.com/sukhilss/laravel-odbc-hive`; licence **MIT**; author **Sukhil S S**.
- Security contact is **emailtosukhil@gmail.com** (ruled by the maintainer).
- **No local PHP or Composer.** Every command runs through Docker: `docker compose run --rm php <command>`.
- `timeout` is unavailable on this macOS host.
- **Commit user-facing docs. Do NOT commit `docs/superpowers/`** — add it to `.gitignore` (Task 1). The spec and plans stay local scratch.
- Never modify anything under `src/`, `tests/`, `config/`, `.github/workflows/`, `composer.json`, `phpunit.xml`, `phpstan.neon`, `pint.json`. **Phase 3 changes no behaviour.** If documenting something reveals a code defect, that is a finding to report, not to fix here.
- Never modify `tests/fixtures/golden-v6-schema.json`.
- Add new commits; do not amend.

## THE RULE THAT MATTERS MOST IN THIS PHASE

**Every code example, command, and factual claim in these documents must be executed or verified before it is written down.** Not "looks right" — actually run, with the output pasted into the task report.

Documentation is the one deliverable where a plausible-sounding fabrication survives review most easily, because nothing fails when it is wrong. A README example that does not run is worse than no README: it costs a user an hour before they conclude the package is broken.

Concretely, that means:

- Every PHP snippet gets executed against the real package and its real output recorded.
- Every shell command gets run.
- Every version number, type mapping and constraint is read from the code or `composer.json`, never recalled.
- Every internal link is checked to resolve to a file that exists.
- If you cannot verify a claim, **do not write it** — write what you can verify, and report the gap.

## Verified reference data

These were extracted by compiling them, not by reading source. Use them verbatim.

**Type mappings** — from `HiveSchemaGrammar`, compiled via a real blueprint:

| Laravel Blueprint call | Emitted Hive type |
|---|---|
| `string('c')` | `string` |
| `char('c', 10)` | `char(10)` |
| `varChar('c', 100)` | `varchar(100)` |
| `text('c')` | `varchar(65535)` |
| `mediumText('c')` | `varchar(65535)` |
| `longText('c')` | `varchar(65535)` |
| `integer('c')` | `int` |
| `bigInteger('c')` | `bigint` |
| `mediumInteger('c')` | `int` |
| `smallInteger('c')` | `smallint` |
| `tinyInteger('c')` | `tinyint` |
| `float('c')` | `float` |
| `double('c')` | `double` |
| `decimal('c')` | `decimal(8, 2)` |
| `boolean('c')` | `boolean` |
| `date('c')` | `date` |
| `dateTime('c')` | `timestamp` |
| `timestamp('c')` | `timestamp` |
| `binary('c')` | `binary` |

**`HiveBlueprint` public API** — from the source:

```
varChar(string $column, ?int $length = null): ColumnDefinition
storedAs(string $format): self
location(string $path): self
delimiter(string $delimiter): self
charset($charset): self
hiveOptions(): HiveTableOptions
```

**Current state:** 141 tests / 267 assertions on Laravel 12.64.0, also green on 11.55.0. `composer lint` PASS, `composer analyse` `[OK] No errors`.

---

## File Structure

**Created:**

| Path | Responsibility |
|---|---|
| `LICENSE.md` | MIT text — currently 0 bytes, the most serious gap in the repo |
| `.gitattributes` | `export-ignore` rules so dev files stay out of the distributed package |
| `docs/limitations.md` | What Hive and this driver cannot do — the honesty document |
| `docs/configuration.md` | DSN formats, env vars, multi-connection setups |
| `docs/schema-builder.md` | Type mapping table and Hive-specific table options |
| `docs/local-development.md` | Docker workflows, the opt-in Hive profile, the golden harness |
| `README.md` | Rewritten: badges, requirements matrix, install, quick start, troubleshooting |
| `CHANGELOG.md` | Keep a Changelog, starting at v7.0.0 |
| `UPGRADE.md` | v6 → v7 migration |
| `CONTRIBUTING.md` | How to work on the package |
| `CODE_OF_CONDUCT.md` | Contributor Covenant 2.1 |
| `SECURITY.md` | Vulnerability reporting policy |
| `.github/ISSUE_TEMPLATE/bug_report.md` | Bug template |
| `.github/ISSUE_TEMPLATE/feature_request.md` | Feature template |
| `.github/PULL_REQUEST_TEMPLATE.md` | PR template |
| `ONCLAUSE-ISSUE-DRAFT.md` (temporary, uncommitted) | Draft GitHub issue for the maintainer to file |

**Modified:** `.gitignore` (add `docs/superpowers/`).

---

### Task 1: Licence and packaging

The licence gap is the most serious item in this phase: `composer.json` has declared MIT since 2019 while `LICENSE.md` has been empty, so anyone performing due diligence would treat the package as unlicensed.

**Files:**
- Create: `LICENSE.md`, `.gitattributes`
- Modify: `.gitignore`

**Interfaces:**
- Consumes: nothing
- Produces: a licensed, correctly-packaged repository. Later tasks link to `LICENSE.md` from the README.

- [ ] **Step 1: Confirm the gap and the metadata**

Run:
```bash
wc -c < LICENSE.md
docker compose run --rm php php -r '$j = json_decode(file_get_contents("/app/composer.json"), true); echo $j["license"], "\n"; echo json_encode($j["authors"]), "\n";'
git log --reverse --format=%ad --date=format:%Y | head -1
```
Expected: `0` bytes; licence `MIT`; author `Sukhil S S`; first commit year `2019`.

Use the year range **2019–2026** in the copyright line — first commit to present.

- [ ] **Step 2: Write `LICENSE.md`**

The standard MIT text, with the copyright line:

```
Copyright (c) 2019-2026 Sukhil S S
```

Use the canonical MIT wording. Do not paraphrase it — a modified licence is not MIT.

- [ ] **Step 3: Create `.gitattributes`**

Keeps development files out of the tarball Composer downloads:

```gitattributes
# Files below are development-only and are excluded from the
# distributed package. Consumers get src/, config/ and the docs.
/.github            export-ignore
/docs/superpowers   export-ignore
/tests              export-ignore
/tools              export-ignore
/docker             export-ignore
/.gitattributes     export-ignore
/.gitignore         export-ignore
/compose.yaml       export-ignore
/phpunit.xml        export-ignore
/phpstan.neon       export-ignore
/pint.json          export-ignore
```

Note `/docs/superpowers` is listed defensively even though Task 1 also gitignores it — belt and braces costs nothing here.

- [ ] **Step 4: Add `docs/superpowers/` to `.gitignore`**

Append:

```
docs/superpowers/
```

The specs and plans are local process scratch, not package documentation. The maintainer ruled they stay out of the repository.

- [ ] **Step 5: Verify the export actually excludes what it should**

This is the real test of `.gitattributes` — the attribute file only takes effect for committed content, so commit first, then verify.

Run:
```bash
git add LICENSE.md .gitattributes .gitignore
git commit -m "docs: add MIT licence text and package export rules"
git archive HEAD | tar -t | sort
```
Expected: `src/`, `config/`, `composer.json`, `LICENSE.md`, `README.md` present; **no** `tests/`, `tools/`, `docker/`, `.github/`, `compose.yaml`, `phpunit.xml`, `phpstan.neon`, `pint.json`.

Paste the full listing into your report. If any dev file survives, the pattern is wrong — fix it and amend before moving on.

- [ ] **Step 6: Confirm `docs/superpowers/` is now ignored**

Run: `git status --short`
Expected: `docs/superpowers/` no longer appears as untracked. `.claude/` and `CLAUDE.md` still will — leave them.

---

### Task 2: `docs/limitations.md`

The honesty document. Several behaviours across Phases 1 and 2 deliberately deferred their user-facing documentation to this file, and three separate code docblocks already point at it.

**Files:**
- Create: `docs/limitations.md`

**Interfaces:**
- Consumes: nothing
- Produces: the file three `src/` docblocks reference, and the target of the README's limitations link (Task 5).

**Every limitation below must be demonstrated by running it before you write it up.** Where a limitation produces an exception, paste the real message. Where it produces surprising SQL, paste the real SQL.

- [ ] **Step 1: Confirm the file is genuinely referenced**

Run: `grep -rn "limitations.md" src/ docs/ README.md 2>/dev/null`
Expected: at least one hit in `src/Schema/Grammars/HiveSchemaGrammar.php`. Note every referrer in your report — they are the reason this file exists.

- [ ] **Step 2: Demonstrate each limitation**

Run each of these and record the actual output. Do not write the document until you have all of them.

```bash
docker compose run --rm php php -r '
require "vendor/autoload.php";
use Sukhil\Database\Hive\Schema\Grammars\HiveSchemaGrammar;
use Sukhil\Database\Hive\Schema\HiveBlueprint;
use Sukhil\Database\Hive\Query\Grammars\HiveQueryGrammar;
use Sukhil\Database\Hive\Tests\Support\BlueprintFactory;

$c = BlueprintFactory::connection();
$g = new HiveSchemaGrammar($c); $c->setSchemaGrammar($g);

// 1. Column modifiers are silently dropped
$b = BlueprintFactory::make("t", function (HiveBlueprint $t) {
    $t->string("a")->nullable();
    $t->integer("b")->default(7);
    $t->integer("c")->unsigned();
}, $g);
$b->create();
echo "modifiers: ", BlueprintFactory::toSql($b, $c, $g)[0], "\n";

// 2. charset() wins over delimiter() silently
$b2 = BlueprintFactory::make("t", function (HiveBlueprint $t) {
    $t->string("a"); $t->charset("UTF-8"); $t->delimiter(",");
}, $g);
$b2->create();
echo "serde-wins: ", BlueprintFactory::toSql($b2, $c, $g)[0], "\n";

// 3. Identifier restriction
$qg = new HiveQueryGrammar($c);
foreach (["my-table", "my table", "select"] as $bad) {
    try { $qg->wrapTable($bad); echo "accepted: $bad\n"; }
    catch (InvalidArgumentException $e) { echo "rejected $bad: ", $e->getMessage(), "\n"; }
}
'
```

Then the schema-operation gaps — confirm which of these actually fail and how:

```bash
docker compose run --rm php php -r '
require "vendor/autoload.php";
use Sukhil\Database\Hive\Schema\Grammars\HiveSchemaGrammar;
use Sukhil\Database\Hive\Tests\Support\BlueprintFactory;
$g = new HiveSchemaGrammar(BlueprintFactory::connection());
foreach (["compileDrop","compileDropIfExists","compileAdd","compileRename","compileColumnListing"] as $m) {
    echo $m, ": ", method_exists($g, $m) ? "present" : "ABSENT", "\n";
}
'
```

- [ ] **Step 3: Write the document**

Cover, each with the demonstrated evidence:

1. **No Hive server was ever used to test this package.** Say it plainly at the top. Correctness rests on unit tests over generated SQL strings plus a golden-parity harness against the pre-port v6 output. Users are the first to run it against real Hive.
2. **Column modifiers are silently dropped** — `nullable()`, `default()`, `unsigned()`, auto-increment. Hive has no such constraints. Show the emitted DDL proving they vanish.
3. **Identifiers must match `[A-Za-z0-9_]`.** Anything else throws `InvalidArgumentException`. Explain why: this driver emits identifiers verbatim rather than quoting them (Hive's double quotes delimit string literals, not identifiers), so validation is the only defence against injection through user-supplied column names. Include the real exception message.
4. **`charset()` silently wins over `delimiter()`.** HiveQL permits one `ROW FORMAT` clause; when both are set the SerDe form is emitted and the delimiter is ignored without warning. Show it.
5. **Schema support is CREATE-only**, and the failure mode is worse than an error. Corrected after demonstration — the original claim in this plan ("they raise `Error`") was wrong. The real behaviour splits in two:
   - `Schema::drop()`, `dropIfExists()`, `rename()` and add-column **silently no-op**, producing an empty statement list. Laravel's `method_exists()` guard in `Blueprint::toSql()` skips the compile step, so the migration reports success while doing nothing. Verified: all three return `[]`.
   - Introspection (`getColumns`, `getTables`, `getIndexes`, `getForeignKeys`, `hasTable`) throws `RuntimeException` with a descriptive message.

   The silent no-op is the dangerous half and must be the emphasis.
6. **Inserts are inlined literals, not bound parameters**, because the Hive ODBC driver does not bind on this path. Note the consequence: `DB::statement('... ?', [$x])` discards its bindings.
7. **Dotted table prefixes and joins** — the residual defect. Under a *dotted* prefix such as `analytics.`, a join's ON clause renders `analytics.e.venue_id` because Illuminate routes the leading segment of a dotted column through table wrapping. Flat prefixes are unaffected. Give the exact shape and say it produces silently-wrong SQL rather than an error.
8. **No Hive ODBC driver ships with this package.** Cloudera's is proprietary and not redistributable; users supply their own.

Write plainly. This document's value is that a user trusts it, which means it must not oversell.

- [ ] **Step 4: Verify every claim in the file traces to output you captured**

Re-read what you wrote against your captured output. Any sentence you cannot point at evidence for gets deleted or softened until you can.

- [ ] **Step 5: Commit**

```bash
git add docs/limitations.md
git commit -m "docs: document Hive and driver limitations"
```

---

### Task 3: `docs/configuration.md` and `docs/schema-builder.md`

**Files:**
- Create: `docs/configuration.md`, `docs/schema-builder.md`

**Interfaces:**
- Consumes: `docs/limitations.md` (Task 2) — both link to it
- Produces: the two reference documents the README links to (Task 5)

- [ ] **Step 1: Read the real configuration shape**

Run: `cat config/hive.php`

Every key you document must exist in that file. The published config includes `'driver' => 'hive'`; note in the doc that this key is required and that omitting it makes the connection unresolvable — that was a real bug in v6.

- [ ] **Step 2: Verify the DSN handling**

Run:
```bash
docker compose run --rm php php -r '
require "vendor/autoload.php";
use Sukhil\Database\Hive\Connectors\HiveConnector;
$m = new ReflectionMethod(HiveConnector::class, "getDsn");
foreach ([["dsn"=>"Driver=Hive;Host=h"], ["dsn"=>"odbc:Driver=Hive"], ["dsn"=>""], []] as $cfg) {
    echo json_encode($cfg), " => ", var_export($m->invoke(new HiveConnector(), $cfg), true), "\n";
}
try { (new HiveConnector())->connect([]); } catch (Throwable $e) { echo "connect([]): ", $e->getMessage(), "\n"; }
'
```
Record the output. The doc must state accurately that the `odbc:` scheme is added when absent, and what happens when the DSN is missing.

- [ ] **Step 3: Write `docs/configuration.md`**

Cover: publishing the config (`php artisan vendor:publish --tag=hive-config` — verify that tag against `src/HiveServiceProvider.php` before writing it), every config key with its env var, the DSN formats accepted, defining a Hive connection directly in `config/database.php`, and running Hive alongside another default connection. Link to `limitations.md`.

- [ ] **Step 4: Write `docs/schema-builder.md`**

Use the verified type-mapping table from this plan's "Verified reference data" section — it was produced by compiling a real blueprint. Also document the four Hive table options with a worked example:

```php
Schema::connection('hive')->create('events', function (HiveBlueprint $table) {
    $table->string('name');
    $table->storedAs('ORC')->location('/warehouse/events');
});
```

**Run that example** and paste the emitted DDL into the document, so readers see exactly what it produces.

On the `HiveBlueprint` type hint — corrected after demonstration; an earlier draft of this plan called it "required to reach `storedAs()`", which is **wrong**. `HiveSchemaBuilder` always constructs a `HiveBlueprint`, and PHP dispatches on the runtime object, so `function (Blueprint $table) { $table->storedAs('ORC'); }` works fine: verified to emit `create table t (n string) STORED AS ORC`. The hint matters for **PHPStan level 6 and IDE autocompletion**, not at runtime. Document both facts precisely rather than the simpler falsehood.

Link to `limitations.md` for the dropped-modifier and SerDe-precedence behaviours rather than restating them.

- [ ] **Step 5: Verify every example**

Execute every PHP snippet in both documents. Paste each snippet's real output into your report. A snippet that does not run does not ship.

- [ ] **Step 6: Commit**

```bash
git add docs/configuration.md docs/schema-builder.md
git commit -m "docs: add configuration and schema builder guides"
```

---

### Task 4: `docs/local-development.md`

**Files:**
- Create: `docs/local-development.md`

**Interfaces:**
- Consumes: nothing
- Produces: the contributor-facing setup doc; `CONTRIBUTING.md` (Task 7) links to it

- [ ] **Step 1: Read the actual Docker setup**

Run: `cat compose.yaml` and `cat docker/php/Dockerfile`

Document what is actually there — three services, two behind profiles. Do not describe an idealised setup.

- [ ] **Step 2: Run every command you intend to document**

```bash
docker compose run --rm php composer install
docker compose run --rm php composer test
docker compose run --rm php composer lint
docker compose run --rm php composer analyse
```
Record each result.

- [ ] **Step 3: Write the document**

Cover:
- The `php` service as the primary loop, with the four commands above.
- The **`hive` profile** — `docker compose --profile hive up -d` starts `apache/hive:4.0.0`, but **no ODBC driver ships with it**. Cloudera's is proprietary; the operator drops their own tarball into the gitignored `docker/drivers/`. Be explicit that this profile does not work out of the box and why.
- The **`capture` profile** and the golden harness: what `tools/capture-golden.sh` does, that it is pinned to commit `ea23f65`, and that `tests/fixtures/golden-v6-schema.json` must never be hand-edited. Explain what the harness proves (no regression against v6) and what it does not (correctness against real Hive).
- Testing against both Laravel majors, including the `--no-blocking` requirement and why.

- [ ] **Step 4: Verify the cross-major instructions actually work**

Run the sequence you documented, then restore:
```bash
docker compose run --rm php composer require --no-update --no-interaction "illuminate/database:^11.0" "illuminate/support:^11.0"
docker compose run --rm php composer update --prefer-dist --no-interaction --no-blocking
docker compose run --rm php php -r 'require "vendor/autoload.php"; echo \Illuminate\Foundation\Application::VERSION, PHP_EOL;'
docker compose run --rm php composer test
git checkout composer.json && rm -rf vendor composer.lock
docker compose run --rm php composer update --prefer-dist --no-interaction
docker compose run --rm php php -r 'require "vendor/autoload.php"; echo \Illuminate\Foundation\Application::VERSION, PHP_EOL;'
```
Expected: `11.x` then `12.64.0`. **The restore matters** — a plain `composer update` can be satisfied by an existing lock, which is why `vendor` and the lock are removed. Confirm the printed version rather than assuming.

- [ ] **Step 5: Commit**

```bash
git add docs/local-development.md
git commit -m "docs: add local development guide"
```

---

### Task 5: README rewrite

**Files:**
- Modify: `README.md`

**Interfaces:**
- Consumes: all four `docs/*.md` files (Tasks 2-4), `LICENSE.md` (Task 1)
- Produces: the package's front door

The current README is 61 lines, documents Laravel 6, and tells users to require `"sukhilss/laravel-odbc-hive": "^6.0"`.

- [ ] **Step 1: Read what it currently claims**

Run: `cat README.md`

Note every claim that is now false — the version constraint, the Laravel version, the DDL example's use of dynamic properties. Your report should list them, because they are what a v6 user currently believes.

- [ ] **Step 2: Derive the requirements matrix from `composer.json`, not memory**

Run: `docker compose run --rm php php -r '$j = json_decode(file_get_contents("/app/composer.json"), true); echo json_encode($j["require"], JSON_PRETTY_PRINT), "\n";'`

- [ ] **Step 3: Write the README**

Structure:

1. **Title and one-line description.**
2. **Badges** — build status pointing at the CI workflow, Packagist version, Packagist downloads, licence. Use the real repository path `sukhilss/laravel-odbc-hive`. **Note in your report that badge images cannot be verified from here** — the CI badge will not render until the workflow has run on a push, and the Packagist badges will not render until v7.0.0 is published. That is expected; do not invent a workaround.
3. **Requirements matrix:**

   | Package | Laravel | PHP |
   |---|---|---|
   | v7.x | 11.x, 12.x | 8.2+ |
   | v6.x | 6.x | 7.2+ |

4. **Installation** — `composer require sukhilss/laravel-odbc-hive`, note auto-discovery, and publishing the config.
5. **Quick start** — a connection config block and one short query example. Both must be executed.
6. **What you need that this package does not provide** — a Hive ODBC driver. Short, prominent, links to `docs/local-development.md`.
7. **Documentation** — links to all four `docs/*.md`.
8. **Troubleshooting** — at minimum: the missing-DSN exception (paste its real message), the identifier-validation exception, and the "driver not found" case when PDO_ODBC is absent.
9. **Contributing / Security / Licence** — short sections linking to the respective files.

- [ ] **Step 4: Verify every link resolves**

Run:
```bash
grep -oE '\]\([^)]+\.md[^)]*\)' README.md | tr -d ']()' | while read -r f; do
  [ -f "$f" ] && echo "OK   $f" || echo "DEAD $f"
done
```
Expected: every line `OK`. A dead link in a README is a broken front door.

- [ ] **Step 5: Verify every code example runs**

Execute each PHP snippet. Paste real output into your report.

- [ ] **Step 6: Commit**

```bash
git add README.md
git commit -m "docs: rewrite README for v7 with requirements matrix and troubleshooting"
```

---

### Task 6: `CHANGELOG.md` and `UPGRADE.md`

**Files:**
- Create: `CHANGELOG.md`, `UPGRADE.md`

**Interfaces:**
- Consumes: `docs/limitations.md` (Task 2) — `UPGRADE.md` links to it
- Produces: the release record

- [ ] **Step 1: Derive the change list from git, not memory**

Run: `git log --oneline ea23f65..HEAD`

That is the complete set of changes in v7.0.0. Every entry you write must trace to one of those commits. **Do not write a changelog entry you cannot point at a commit for.**

- [ ] **Step 2: Write `CHANGELOG.md`**

Keep a Changelog 1.1.0 format, Semantic Versioning. One `## [7.0.0]` entry with `### Added` / `### Changed` / `### Fixed` / `### Removed`, plus an `## [Unreleased]` heading above it.

Note beneath the 7.0.0 entry that v6.0.1–v6.0.4 shipped in 2019 without a changelog, and that records begin here. Do not fabricate entries for those tags.

The headline items, each traceable to a commit:

- **Changed:** PHP floor 7.2 → 8.2; Laravel 6 → 11/12; config moved to `config/hive.php`; class renames (`Schema\Builder` → `Schema\HiveSchemaBuilder`, and the two `HiveGrammar`s to `HiveQueryGrammar`/`HiveSchemaGrammar`); table options moved from dynamic properties to methods; publish tag `config` → `hive-config`.
- **Fixed:** insert escaping no longer uses `PDO::quote()` (which PDO_ODBC does not implement and which returned `false`); insert values no longer escape via the *default* connection's PDO; batch inserts with reordered keys no longer pair values with the wrong columns; `HiveConnection` no longer rejects the lazy-connection closure Laravel passes; the published config now carries `'driver' => 'hive'`; malformed table-options DDL (missing space, duplicate `ROW FORMAT`, wrong clause order); `statement()` no longer reports a zero-row DDL as failure.
- **Added:** identifier validation on the insert and query paths; support for schema-qualified names and dotted table prefixes; a test suite; PHPStan level 6; Pint; CI.
- **Removed:** `phpcs.xml`; the inert `provides()`.

- [ ] **Step 3: Write `UPGRADE.md`**

A v6 → v7 guide organised by what a user must *do*, not by what changed internally. For each: what breaks, the symptom they will see, and the fix.

Cover at minimum:

| # | Change | What the user does |
|---|---|---|
| 1 | PHP 8.2, Laravel 11/12 required | Upgrade both |
| 2 | Config moved to `config/hive.php` | Re-publish with `--tag=hive-config` |
| 3 | Publish tag renamed | `--tag=config` no longer matches |
| 4 | `'driver' => 'hive'` now required | Included in the published file; add it to hand-written configs |
| 5 | Dynamic table properties removed | `$table->format = 'ORC'` becomes `$table->storedAs('ORC')`, and the closure must type-hint `HiveBlueprint` |
| 6 | Class renames | Update any direct imports |
| 7 | **Identifiers must match `[A-Za-z0-9_]`** | Previously anything was emitted verbatim; now non-conforming names throw. Link to `docs/limitations.md`. |
| 8 | **Insert escaping changed** | Generated insert SQL differs for every user. Explain that v6 escaped via the default connection's PDO — usually MySQL — and that PDO_ODBC does not implement `quote()` at all, so string inserts were malformed whenever Hive *was* the default connection. |

Item 8 is the one most likely to surprise someone mid-upgrade; give it the most space.

- [ ] **Step 4: Verify every claim against the code**

Spot-check at least the publish tag and the class names:
```bash
grep -n "publishes" src/HiveServiceProvider.php
ls src/Schema/ src/Query/Grammars/
```
Correct anything that does not match.

- [ ] **Step 5: Commit**

```bash
git add CHANGELOG.md UPGRADE.md
git commit -m "docs: add changelog and v6 to v7 upgrade guide"
```

---

### Task 7: Community health files and the follow-up issue draft

**Files:**
- Create: `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, `.github/ISSUE_TEMPLATE/bug_report.md`, `.github/ISSUE_TEMPLATE/feature_request.md`, `.github/PULL_REQUEST_TEMPLATE.md`
- Create (temporary, **do not commit**): `ONCLAUSE-ISSUE-DRAFT.md`

**Interfaces:**
- Consumes: `docs/local-development.md` (Task 4) — `CONTRIBUTING.md` links to it
- Produces: nothing downstream; this is the last task

- [ ] **Step 1: Write `CONTRIBUTING.md`**

Cover: the Docker-only workflow (link to `docs/local-development.md` rather than repeating it), the three gates a PR must pass (`composer test`, `composer lint`, `composer analyse`), and two project-specific rules a newcomer would otherwise violate:

- **Version-dependent code is confined to four sites** — `HiveConnection::configureGrammar`, `HiveSchemaBuilder::createBlueprint`, and the two grammar constructors. New Hive type mappings go in `HiveSchemaGrammar`, never in a version branch.
- **`PDO::quote()` must never be used.** PDO_ODBC does not implement it. Escaping goes through `HiveValueQuoter`; identifier validation through `HiveIdentifier`.

Also state that PHPStan runs at level 6 with **no baseline**, so new violations must be fixed or suppressed with a written justification.

- [ ] **Step 2: Write `CODE_OF_CONDUCT.md`**

Contributor Covenant 2.1, verbatim, with `emailtosukhil@gmail.com` as the enforcement contact.

- [ ] **Step 3: Write `SECURITY.md`**

Supported versions (v7.x supported; v6.x end-of-life), how to report privately to **emailtosukhil@gmail.com**, and what to expect.

Include one project-specific note: this driver **emits identifiers verbatim rather than quoting them**, so `HiveIdentifier` validation is the primary injection defence — reports touching identifier handling, `HiveValueQuoter`, or `HiveTableWrapper` should be treated as security-relevant.

- [ ] **Step 4: Write the three GitHub templates**

`bug_report.md` should ask for: package version, Laravel version, PHP version, the ODBC driver in use, the generated SQL if known, and a minimal reproduction. The driver question matters because the package cannot be tested against real Hive by its maintainer.

`feature_request.md` and `PULL_REQUEST_TEMPLATE.md` — short and conventional. The PR template should include a checklist for the three gates.

- [ ] **Step 5: Draft the follow-up issue**

Write `ONCLAUSE-ISSUE-DRAFT.md` in the repository root, **uncommitted**, containing a ready-to-paste GitHub issue for the residual defect:

Title: `Dotted table prefix produces schema-qualified alias in JOIN ON clauses`

Body must include: the exact reproduction (a dotted prefix such as `analytics.` plus a join with an alias-qualified ON column), the observed output `analytics.e.venue_id`, the root cause (Illuminate's `Grammar::wrapSegments()` routes the leading segment of any dotted column through `wrapTable()`), why upstream Laravel is unaffected (MySQL quotes identifiers, so `` `analytics.e` `` is one opaque token; Hive does not quote, so it parses as a real `schema.table` reference), the fact that flat prefixes are unaffected, and that it produces silently-wrong SQL rather than an error.

Tell the maintainer in your report that this file is deliberately uncommitted and is theirs to file and delete.

- [ ] **Step 6: Verify all links across every document**

Run:
```bash
for f in README.md CONTRIBUTING.md SECURITY.md CODE_OF_CONDUCT.md UPGRADE.md CHANGELOG.md docs/*.md; do
  grep -oE '\]\([^)#]+\.md[^)]*\)' "$f" 2>/dev/null | tr -d ']()' | while read -r t; do
    d=$(dirname "$f"); [ -f "$t" ] || [ -f "$d/$t" ] || echo "DEAD in $f -> $t"
  done
done
echo "link check complete"
```
Expected: no `DEAD` lines.

- [ ] **Step 7: Confirm the package still builds and nothing behavioural moved**

Run:
```bash
docker compose run --rm php composer test
docker compose run --rm php composer lint
docker compose run --rm php composer analyse
git status --short
```
Expected: `OK (141 tests, 267 assertions)`, lint PASS, `[OK] No errors`. Working tree should show `ONCLAUSE-ISSUE-DRAFT.md` untracked plus `.claude/` and `CLAUDE.md`; **`docs/superpowers/` must not appear** (Task 1 ignored it).

Phase 3 changes no code, so any movement in those numbers means something went wrong.

- [ ] **Step 8: Commit**

```bash
git add CONTRIBUTING.md CODE_OF_CONDUCT.md SECURITY.md .github/ISSUE_TEMPLATE .github/PULL_REQUEST_TEMPLATE.md
git commit -m "docs: add community health files and issue templates"
```

Do **not** add `ONCLAUSE-ISSUE-DRAFT.md`.

---

## Self-Review

**Spec coverage:**

| Spec requirement (Phase 3) | Task |
|---|---|
| Real MIT `LICENSE.md` | 1 |
| `.gitattributes` with `export-ignore` | 1 |
| `README.md` — badges, matrix, install, config, usage, troubleshooting | 5 |
| `CHANGELOG.md`, Keep a Changelog | 6 |
| `UPGRADE.md` v6 → v7 | 6 |
| `docs/configuration.md` | 3 |
| `docs/schema-builder.md` | 3 |
| `docs/limitations.md` | 2 |
| `docs/local-development.md` | 4 |
| `CONTRIBUTING.md` | 7 |
| `CODE_OF_CONDUCT.md` | 7 |
| `SECURITY.md` | 7 |
| Issue and PR templates | 7 |
| `.gitignore` up to date | 1 |

Maintainer rulings honoured: user-facing docs committed and `docs/superpowers/` gitignored (Task 1); the ON-clause defect both documented (Task 2) and drafted as an issue (Task 7); `emailtosukhil@gmail.com` as security contact (Task 7); changelog starting at v7.0.0 with v6 acknowledged (Task 6).

**Placeholder scan:** no TBDs. Every task carries either literal content or a command whose output becomes the content. Tasks 2, 3, 5 and 6 deliberately instruct the implementer to *derive* content by running code rather than embedding my own prose — that is the anti-fabrication mechanism this phase needs, not a placeholder. The one genuinely unverifiable item, badge rendering, is called out explicitly in Task 5 Step 3 with an instruction not to invent a workaround.

**Type consistency:** the type-mapping table in "Verified reference data" was produced by compiling a real blueprint and is referenced by Task 3 Step 4. `HiveBlueprint`'s public API is quoted from source and used in Tasks 3 and 6. The four version-branching sites are named identically in Task 7 and in Phases 1-2.

**Known risks:**

1. **Badges cannot be verified.** The CI badge needs a pushed workflow run; the Packagist badges need v7.0.0 published. Both will render as broken until then. Task 5 says so rather than pretending otherwise.
2. **Task 4's cross-major verification mutates dependency state.** It pins to Laravel 11 and must restore. The step deletes `vendor/` and the lock before restoring, because a plain `composer update` can be satisfied by an existing lock and silently leave the repo on Laravel 11.
3. **This phase documents a package no one has run against real Hive.** The strongest thing `limitations.md` can do is say so at the top. Task 2 requires exactly that.
