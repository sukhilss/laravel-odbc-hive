# Upgrading from v6 to v7

This is a v6 → v7 guide organised by what you need to *do*. For the full
list of what changed, see [`CHANGELOG.md`](CHANGELOG.md). For known gaps and
rough edges that are not new in v7 (dropped column modifiers, CREATE-only
schema support, and more), see [`docs/limitations.md`](docs/limitations.md).

Two changes affect every application that upgrades, whether or not your code
touches the areas that changed: read items 7 and 8 even if you skim the rest.

## 1. Upgrade PHP and Laravel

v7 requires **PHP 8.2+** and **Laravel 11.x or 12.x**. If you're on Laravel 6
/ PHP 7.2, stay on `^6.0` of this package (tag `v6.0.4`, commit `0a69cf8`)
until you've upgraded your application — but note that
[`SECURITY.md`](SECURITY.md) declares v6.x end of life, so no security
patches will be issued for it. There's no intermediate version to move
through — this package didn't track Laravel 7 through 10.

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

Three of those four did something at `v6.0.4`. `charset` did not: the
`ROW FORMAT SERDE` clause that reads it was added *after* the tag, in the
untagged master commits `95d33bf`/`ea23f65`, so `v6.0.4`'s grammar never
looked at `$table->charset` and setting it changed nothing in the DDL. If
you are coming from a released v6 there is nothing to migrate for `charset`
— calling `charset()` in v7 gives you a SerDe clause you did not have
before. If you were tracking master, `charset()` is the replacement.

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

**`charset` is the exception, and it is silent.** Laravel's base
`Illuminate\Database\Schema\Blueprint` declares `public $charset;`, so
`$table->charset = 'UTF-8'` sets a real inherited property rather than
creating a dynamic one: no deprecation notice is raised, and v7's grammar
never reads that property, so the option is dropped with **no signal at
all**. Verified by assignment against a real `HiveBlueprint` — `format`,
`delimiter`, and `location` each emit `Creation of dynamic property ... is
deprecated`; `charset` emits nothing. Grep your migrations for
`->charset =` specifically; the deprecation notices will not find it for
you.

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

v6's exposure here was narrower than "everything was raw." Checked directly
against the pre-port source (tag `v6.0.4` = commit `0a69cf8`; unchanged
through `ea23f65`, the port's base):

- **Table identifiers reached the SQL unquoted** in both grammars. The
  query grammar overrode `wrapTable()` with a bare `return $table;` — no
  quoting, no prefixing, no validation. The schema grammar did not override
  `wrapTable()` at all; it inherited Illuminate's, which routes the name
  through the grammar's own `wrapValue()` — and that override (next bullet)
  supplies no quote characters, so the table name came out unquoted there
  too. Either way, a table name built from unvalidated input reached the
  generated SQL essentially unchanged. This was a real exposure wherever a
  table name could come from outside your own code.
- **Schema-side (DDL) column identifiers were effectively unquoted too** —
  the schema grammar's `wrapValue()` doubled embedded double quotes
  (`str_replace('"', '""', $value)`) but supplied no surrounding quote
  characters for that doubling to close into, so the escaping had nothing
  to protect.
- **Query-side column identifiers, by contrast, were already quoted and
  escaped** — this path had no override and inherited the base Illuminate
  grammar's `"name"`-style double-quote wrapping with `"`-doubling
  unchanged. This path was not raw in v6.

(A brief regression on the query-side column path — an in-development
rewrite of the query grammar that dropped the inherited quoting entirely —
was introduced and fixed within this port's own commit history before
v7.0.0 shipped; it never reached a release. See commits `bddcf8a` and its
fix `1a06929` if you want the detail.)

v7 replaces all of the above — table identifiers in both grammars, and
every column and alias identifier — with one validator
(`Sukhil\Database\Hive\Support\HiveIdentifier`): every identifier is now
checked against `/^[A-Za-z0-9_]+\z/` per dot-separated segment and throws
`InvalidArgumentException` for anything that doesn't match, rather than
being quoted (the previously-safe query-side column path) or passed
through unchanged (everywhere else).

If your schema uses table or column names with hyphens, spaces, or other
punctuation, migrations and queries that reference them will now throw
where they previously worked — worked safely, for query-side column names,
and worked unsafely for table names. There is no configuration flag to
restore the old behaviour.

This is a character-class check only, not a reserved-word check — a name
like `select` still passes and is emitted unchanged, so Hive itself may
still reject it. See "Identifiers must match `[A-Za-z0-9_]`" in
[`docs/limitations.md`](docs/limitations.md) for the exact pattern, example
error messages, and the reserved-word caveat.

## 8. Inserts are no longer parameterised — values are inlined into the SQL

This is the change most likely to surprise you mid-upgrade, and it affects
**every** application using this driver to insert data, whether or not you
changed anything in your own code.

**What `v6.0.4` did:** `compileInsert()` emitted `?` placeholders, and the
values travelled as bound parameters:

```php
// v6.0.4 — src/Query/Grammars/HiveGrammar.php
$parameters = collect($values)->map(function ($record) {
    return '('.$this->parameterize($record).')';
})->implode(', ');
```

`HiveConnection::statement()` matched that, preparing the statement and
binding before executing:

```php
// v6.0.4 — src/HiveConnection.php
$statement = $this->getPdo()->prepare($query);
$this->bindValues($statement, $this->prepareBindings($bindings));

return $statement->execute();
```

**What v7 does:** values are written directly into the generated SQL as
literals, escaped by a new
`Sukhil\Database\Hive\Support\HiveValueQuoter` using Hive's own C-style
escaping rules. `HiveConnection::statement()` correspondingly runs
`$this->getPdo()->exec($query)` and never applies `$bindings` to `$query`.
The stated reason is the Hive ODBC driver's lack of parameter-binding
support on the statement path this package uses.

Two consequences you need to act on:

- **The SQL generated for every insert differs from `v6.0.4`'s.** Where v6
  produced `insert into events (name) values (?)` plus a binding, v7
  produces `insert into events (name) values ('OKane')`. If you have tests
  or fixtures asserting against literal generated insert SQL (rather than
  against the effect of the insert), they will need updating.
- **`DB::statement($sql, $bindings)` silently discards `$bindings`.** Under
  `v6.0.4` those bindings were applied. Under v7 a literal `?` in `$sql` is
  sent to the driver unchanged and the values never arrive — the call still
  returns `true`. Any raw statement you write with placeholders must now
  build the literal values into the SQL string itself. See "Inserts are
  inlined literals, not bound parameters" in
  [`docs/limitations.md`](docs/limitations.md) for a demonstrated example.

(If you were tracking untagged master rather than a release, the inlining
itself is not new to you: commits `95d33bf` and `ea23f65` — two commits
*past* the `v6.0.4` tag, and this port's base — had already replaced the
bound parameters with literals escaped by `\DB::getPdo()->quote()`. That
escaping was broken in two ways: `\DB::getPdo()` is the *default*
connection's PDO, not the Hive one, and PDO_ODBC does not implement
`quote()` at all — it returns `false`, which concatenates into a SQL string
as the empty string. v7's `HiveValueQuoter` replaces it. None of this
reached a release, so if you are upgrading from `v6.0.4` you were never
exposed to it — the change that matters to you is the binding → inlining
switch above. Documented here in the same spirit as the in-port regression
noted under item 7.)

If you were relying on a workaround for the untagged-master escaping bug —
pre-escaping values yourself before calling `insert()`, or avoiding string
columns — remove it; v7 will double-escape if you keep it in place.
