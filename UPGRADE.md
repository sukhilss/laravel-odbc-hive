# Upgrading from v6 to v7

This is a v6 → v7 guide organised by what you need to *do*. For the full
list of what changed, see [`CHANGELOG.md`](CHANGELOG.md). For known gaps and
rough edges that are not new in v7 (dropped column modifiers, CREATE-only
schema support, and more), see [`docs/limitations.md`](docs/limitations.md).

Two changes affect every application that upgrades, whether or not your code
touches the areas that changed: read items 7 and 8 even if you skim the rest.

## 1. Upgrade PHP and Laravel

v7 requires **PHP 8.2+** and **Laravel 11.x or 12.x**. If you're on Laravel 6
/ PHP 7.2, stay on `^6.0` of this package (tag `v6.0.4`) until you've
upgraded your application. There's no intermediate version to move through —
this package didn't track Laravel 7 through 10.

## 2. Re-publish the config file

The config file moved from the package's internal `src/config/hive.php` to
`config/hive.php` in the repository, and the file it publishes to your
application is unchanged in destination (`config/hive.php`) but has a new
source and a new publish tag (see item 3). If you previously published a
config file, re-publish it to pick up any new keys:

```bash
php artisan vendor:publish --tag=hive-config --force
```

Compare against your existing `config/hive.php` before overwriting if you've
customised it — `--force` overwrites unconditionally.

## 3. Update the publish tag

The publish tag changed from `config` to `hive-config`. If you have a
deployment script, README snippet, or CI step that runs:

```bash
php artisan vendor:publish --tag=config
```

**this now matches nothing and silently publishes no file.** There's no
error — the command just exits having done nothing, which is easy to miss
in a script that doesn't check for the published file afterward. Update the
tag to `hive-config`.

## 4. Add `'driver' => 'hive'` to hand-written configs

The published `config/hive.php` now includes `'driver' => 'hive'` under the
connection array. This key was missing from v6's published default, which
meant a connection built purely from the published file could never resolve.

If you hand-wrote your `hive` connection entry in `config/database.php`
(rather than using the published file, or the connection this package
registers automatically), check that it has `'driver' => 'hive'`. Without
it, Laravel can't find the connector/resolver for the connection and it
will fail to resolve — the symptom is typically an error about an unknown
or unsupported driver when you first touch `DB::connection('hive')` or
`Schema::connection('hive')`.

## 5. Replace dynamic table-option properties with methods

v6's `HiveBlueprint` table options were plain dynamic properties, set like:

```php
Schema::connection('hive')->create('events', function ($table) {
    $table->string('name');
    $table->format = 'ORC';
    $table->location = '/warehouse/events';
    $table->delimiter = ',';
    $table->charset = 'UTF-8';
});
```

In v7 these are typed fluent methods instead:

```php
use Sukhil\Database\Hive\Schema\HiveBlueprint;

Schema::connection('hive')->create('events', function (HiveBlueprint $table) {
    $table->string('name');
    $table->storedAs('ORC');
    $table->location('/warehouse/events');
    $table->delimiter(',');
    $table->charset('UTF-8');
});
```

If you still write `$table->format = 'ORC'` against a v7 `HiveBlueprint`,
PHP will raise a deprecation notice (dynamic property creation, deprecated
since PHP 8.2) rather than setting anything the grammar reads — the option
will silently have no effect on the generated DDL.

Also type-hint the closure parameter as `HiveBlueprint`, not the base
`Blueprint`. `HiveSchemaBuilder` always constructs a real `HiveBlueprint`
regardless of the hint, so the four methods above do still work at runtime
even under a `Blueprint` hint — but PHPStan/Larastan (this package is
analysed at level 6) reports `storedAs()`, `location()`, `delimiter()`,
`charset()`, and `varChar()` as undefined methods without the correct hint,
and your IDE won't autocomplete them either.

## 6. Update any direct class imports

Three classes were renamed. If you import them directly (rather than only
using `Schema::connection('hive')` / `DB::connection('hive')`), update the
imports:

| v6 | v7 |
|---|---|
| `Sukhil\Database\Hive\Schema\Builder` | `Sukhil\Database\Hive\Schema\HiveSchemaBuilder` |
| `Sukhil\Database\Hive\Query\Grammars\HiveGrammar` | `Sukhil\Database\Hive\Query\Grammars\HiveQueryGrammar` |
| `Sukhil\Database\Hive\Schema\Grammars\HiveGrammar` | `Sukhil\Database\Hive\Schema\Grammars\HiveSchemaGrammar` |

The old class names no longer exist — a leftover `use` statement against
them fails at load time (class not found), not silently.

## 7. Identifiers must now match `[A-Za-z0-9_]`

v6 emitted table, column, and database identifiers **verbatim** — whatever
string you gave it went straight into the generated SQL, unescaped and
unvalidated. v7 validates every identifier (`Sukhil\Database\Hive\Support\HiveIdentifier`)
against `/^[A-Za-z0-9_]+\z/` per dot-separated segment before emitting it,
and throws `InvalidArgumentException` for anything that doesn't match.

If your schema uses table or column names with hyphens, spaces, or other
punctuation, migrations and queries that reference them will now throw
where they previously (silently, and unsafely) worked. This is a deliberate
security fix, not a relaxable option: v6's verbatim emission meant an
array key from something like `$request->all()` used as an insert or select
column could inject arbitrary SQL. There is no configuration flag to
restore the old behaviour.

This is a character-class check only, not a reserved-word check — a name
like `select` still passes and is emitted unchanged, so Hive itself may
still reject it. See "Identifiers must match `[A-Za-z0-9_]`" in
[`docs/limitations.md`](docs/limitations.md) for the exact pattern, example
error messages, and the reserved-word caveat.

## 8. Insert escaping changed — expect different generated SQL for every insert

This is the change most likely to surprise you mid-upgrade, and it affects
**every** application using this driver to insert data, whether or not you
changed anything in your own code.

**What v6 did:** string values in `insert()` calls were escaped by calling
`\DB::getPdo()->quote($value)` — that is, the PDO instance behind Laravel's
**default** connection (`DB_CONNECTION`, usually MySQL or PostgreSQL), not
the Hive connection the insert was actually going to. Two failure modes
followed from this:

- If Hive **was** your default connection, `\DB::getPdo()` returned the
  Hive PDO_ODBC handle. PDO_ODBC does not implement `PDO::quote()` at all —
  it returns `false`. `false` concatenated into a SQL string becomes the
  empty string, so v6 silently produced malformed insert SQL (an unquoted,
  unescaped value dropped entirely, or a syntax error) for every string
  column, on every insert.
- If Hive was **not** your default connection (the common case — most
  applications default to MySQL/PostgreSQL and add Hive as a secondary
  analytics connection), values were escaped using that *other* database's
  quoting rules — e.g. MySQL's — and the resulting string sent to Hive,
  which has different escaping conventions (C-style string escaping).
  Some values happened to escape compatibly; others did not.

**What v7 does:** insert values are escaped through a new
`HiveValueQuoter`, using Hive's own C-style escaping rules, entirely
independent of whichever connection happens to be the application default.

**What to expect:** the exact SQL this driver generates for an insert will
differ from what v6 generated — for everyone, not just applications with
unusual data. If you have tests or fixtures that assert against literal
generated insert SQL (rather than against the effect of the insert), they
will need updating. If you were relying on any workaround for the v6
escaping bug — pre-escaping values yourself before calling `insert()`, or
avoiding string columns — remove it; v7 will now double-escape if you keep
it in place. If Hive was your default connection under v6, your string
inserts were malformed and this is a correctness fix you should not need to
adjust for — just verify inserts that previously "worked" (or silently
failed) now produce correct data.
