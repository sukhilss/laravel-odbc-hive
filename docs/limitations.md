# Limitations

**No Hive server has ever been used to test this package.** Every claim about
generated SQL in this repository rests on two things: unit tests that assert
against the literal SQL strings the grammars produce, and a golden-parity
harness (`tests/Unit/Schema/GoldenParityTest.php`, backed by
`tests/fixtures/golden-v6-schema.json`) that pins the schema grammar's DDL
output against what the pre-port Laravel 6 code produced for the same
migrations. That harness proves the port introduced no regression relative to
v6. It proves nothing about whether v6's SQL — or this fork's — is valid
HiveQL on an actual cluster. If you are the first person to run this against
a real Hive server, you are also the first person to find out whether it
works. Please report back (see the issue tracker) if you do.

Everything below was produced by actually running the code shown, on the
version of Laravel installed in this repository's Docker image
(`illuminate/database` 12.64.0), not inferred from reading the source.

## Column modifiers are silently dropped

Hive has no `NOT NULL`, `DEFAULT`, `UNSIGNED`, or auto-increment constraint,
so `HiveSchemaGrammar` declares empty `$modifiers` and `$serials` arrays.
Calling `->nullable()`, `->default()`, `->unsigned()`, or `->increments()` on
a column compiles without error, but the modifier is simply absent from the
emitted DDL — there is no warning that it was dropped.

Running:

```php
$t->string('a')->nullable();
$t->integer('b')->default(7);
$t->integer('c')->unsigned();
```

produces:

```sql
create table t (a string, b int, c int)
```

No `NOT NULL`, no `default 7`, no `unsigned` anywhere. Likewise:

```php
$t->increments('id');
```

produces plain `id int` — no primary key, no auto-increment, no `NOT NULL`.
If your migrations rely on the database to enforce nullability, defaults, or
auto-incrementing IDs, Hive will not do it; that logic has to live in your
application.

## Identifiers must match `[A-Za-z0-9_]`

Every identifier this driver emits — table names, column names, database
names — is written into the SQL string **verbatim, not quoted**. This is
deliberate: Hive's double quote delimits a string *literal*, not an
identifier, so wrapping a name in `"..."` the way the base Laravel grammar
does would be wrong, and Hive has no backtick-style identifier quoting to
fall back on either. Because the identifier *is* the SQL rather than an
escaped fragment of it, validation (`Sukhil\Database\Hive\Support\HiveIdentifier`)
is the only thing standing between a user-supplied column or table name and
the query the driver executes — a real concern given how often array keys
from `$request->all()` end up as column names.

The permitted pattern is `/^[A-Za-z0-9_]+\z/` per dot-separated segment.
Anything else throws `InvalidArgumentException` with the real identifier
in the message:

```
rejected my-table: Unsafe Hive identifier 'my-table': only letters, digits and underscores are permitted.
rejected my table: Unsafe Hive identifier 'my table': only letters, digits and underscores are permitted.
```

This is a character-class check only, not a reserved-word check: a name
like `select` matches the pattern and is accepted unchanged —

```
accepted: select
```

— so it will still be emitted verbatim. If your schema uses a Hive
reserved word as a column or table name, this validator will not catch it;
Hive itself will reject the resulting query.

## `charset()` silently wins over `delimiter()`

HiveQL permits exactly one `ROW FORMAT` clause per `CREATE TABLE`. If a
blueprint sets both a SerDe-driven charset and a delimiter, the grammar
emits the SerDe form and drops the delimiter without any warning:

```php
$t->string('a');
$t->charset('UTF-8');
$t->delimiter(',');
```

produces:

```sql
create table t (a string) ROW FORMAT SERDE 'org.apache.hadoop.hive.serde2.lazy.LazySimpleSerDe' WITH SERDEPROPERTIES ('serialization.encoding'='UTF-8', 'store.charset'='UTF-8', 'retrieve.charset'='UTF-8')
```

There is no trace of `TERMINATED BY ','` anywhere in the output. If you
need a delimiter-based row format, do not also call `charset()` on the
same blueprint.

## Schema support is CREATE-only

`HiveSchemaGrammar` implements `compileCreate()` and the column `type*()`
compilers. It does not implement any of `compileDrop`, `compileDropIfExists`,
`compileAdd`, `compileRename`, `compileColumns`, `compileTables`,
`compileIndexes`, or `compileForeignKeys`. Confirmed by checking each with
`method_exists()`:

```
compileDrop: ABSENT
compileDropIfExists: ABSENT
compileAdd: ABSENT
compileRename: ABSENT
compileColumnListing: ABSENT
```

**These failure modes are not uniform, and neither is a bare `Error`** — the
actual behaviour splits in two, and the first half is worse than an
exception:

- **`Schema::drop()`, `Schema::dropIfExists()`, `Schema::rename()`, and
  adding a column to an existing table via `Schema::table()`** do not throw
  at all. Laravel's `Blueprint::toSql()` looks up a `compile{Command}` method
  on the grammar with `method_exists()` before calling it, and silently skips
  the command when the method is missing. Since `HiveSchemaGrammar` defines
  none of `compileDrop`, `compileDropIfExists`, `compileAdd`, or
  `compileRename`, these calls compile to zero SQL statements and execute
  nothing. There is no exception and no warning — the call simply returns
  successfully having done nothing. Demonstrated directly: calling
  `$builder->drop('t')`, `->dropIfExists('t')`, `->rename('t', 't2')`, and
  `->table('t3', fn ($t) => $t->string('x'))` against a real
  `HiveSchemaBuilder` all returned with no exception and produced no SQL. A
  caller who runs `Schema::dropIfExists('old_table')` expecting the table
  gone will find it untouched, with nothing in the return value or logs to
  say so.
- **`Schema::hasTable()`, `getTables()`, `getColumns()`, `getColumnListing()`,
  `getIndexes()`, and `getForeignKeys()`** call the grammar's
  `compileTables()` / `compileColumns()` / etc. directly rather than through
  a `method_exists()` guard. `HiveSchemaGrammar` doesn't override any of
  these, so they fall through to Laravel's base `Grammar` class, which does
  throw — a `RuntimeException`, not a bare `Error`, with a descriptive
  message:

  ```
  getColumnListing: RuntimeException: This database driver does not support retrieving columns.
  getColumns: RuntimeException: This database driver does not support retrieving columns.
  getTables: RuntimeException: This database driver does not support retrieving tables.
  getIndexes: RuntimeException: This database driver does not support retrieving indexes.
  getForeignKeys: RuntimeException: This database driver does not support retrieving foreign keys.
  hasTable: RuntimeException: This database driver does not support retrieving tables.
  ```

  (`compileColumnListing`, the method the older Laravel API named, isn't
  called anywhere in the installed Laravel 12 framework any more — its
  absence has no runtime effect on this version; `compileColumns` is what
  `getColumns()`/`getColumnListing()` actually reach, and that's what
  throws above.)

In short: **destructive and structural schema changes fail silently; schema
introspection fails loudly.** If you need to drop, rename, or alter a table,
or migrate away from one, write the DDL yourself and run it with
`DB::statement()` — do not rely on the `Schema::` facade for anything beyond
`create()`.

## Inserts are inlined literals, not bound parameters

The Hive ODBC driver does not support parameter binding on the statement
path this package uses, so `HiveQueryGrammar::compileInsert()` writes values
directly into the SQL string (escaped through `HiveValueQuoter`, never
`PDO::quote()`, which `PDO_ODBC` doesn't implement):

```php
$qg->compileInsert($query, ['name' => 'OKane', 'note' => "it's fine"]);
```

produces:

```sql
insert into events (name, note) values ('OKane', 'it\'s fine')
```

— a fully-formed statement with no `?` placeholders at all for insert
values. The consequence reaches further than `insert()`, though:
`HiveConnection::statement()` is implemented as `$this->getPdo()->exec($query)`
and never applies `$bindings` to `$query` in any way. Any raw SQL you pass
through `DB::statement($sql, $bindings)` has its bindings **discarded
outright** — a literal `?` in `$sql` is sent to the driver unchanged.
Demonstrated against a real `HiveConnection`:

```php
$connection->statement('insert into t (id) values (?)', [42]);
```

returned `true` (no error), but the row that landed in the table was
`{"id":null}` — the `42` binding never reached the query; the literal `?`
was executed as-is. If you write raw statements with placeholders, build
the literal values into the SQL string yourself (e.g. via
`HiveValueQuoter`-style escaping) rather than passing a `$bindings` array.

## Dotted table prefixes and joins

This is a known, unfixed defect, not a hypothetical edge case.

A *flat* table prefix (e.g. `myapp_`) works correctly everywhere, including
inside a join's `ON` clause:

```php
// connection prefix: 'myapp_'
DB::table('events as e')->join('venues as v', 'e.venue_id', '=', 'v.id');
```

```sql
select * from myapp_events as myapp_e inner join myapp_venues as myapp_v on myapp_e.venue_id = myapp_v.id
```

A *dotted* prefix (e.g. `analytics.`, used to target a Hive database/schema)
breaks only the `ON` clause. The table and alias portions of `FROM`/`JOIN`
are correct, but the join condition gets the whole dotted prefix glued onto
the alias:

```php
// connection prefix: 'analytics.'
DB::table('events as e')->join('venues as v', 'e.venue_id', '=', 'v.id');
```

```sql
select * from analytics.events as e inner join analytics.venues as v on analytics.e.venue_id = analytics.v.id
```

`analytics.e.venue_id` is not a valid reference to the `e` alias — it reads
as database `analytics`, table `e`, column absent, or is simply rejected by
Hive, depending on parser behaviour. This happens because Laravel's
inherited `Grammar::wrapSegments()` routes the leading segment of any dotted
column reference (`e.venue_id`) through the same `wrapTable()` used for real
table names, and `wrapTable()` prepends the configured prefix to whatever it
is given — it cannot distinguish "this is a real table" from "this is an
alias that happens to be the first segment of a dotted column." A flat
prefix concatenates cleanly (`myapp_` + `e` = `myapp_e`, still a single
valid identifier matching the alias used in `FROM`); a dotted prefix does
not concatenate, it *qualifies* — `analytics.` + `e` produces a two-part
name whose first part is a schema Hive will actually try to resolve, which
is where the meaning breaks and MySQL-style prefixing does not.

**This produces silently wrong SQL, not an error** — the query compiles and
would be sent to the server as-is. If you use a dotted connection prefix
(targeting a non-default Hive database) and also write joins with table
aliases, inspect the compiled SQL before trusting it. This is filed as a
known issue rather than fixed in this release; flat prefixes remain the safe
option if you rely on joins.

## No Hive ODBC driver ships with this package

This package talks to Hive over `PDO_ODBC`, but it does not, and cannot,
bundle an ODBC driver for Hive. `composer.json` only *suggests* `ext-odbc`
("Required to connect to a real Hive server over ODBC.") — it is not a hard
requirement, because installing this package doesn't get you a working
connection on its own. Cloudera's Hive ODBC driver, the one most commonly
used in practice, is proprietary and distributed under Cloudera's own
license terms, not redistributable via Composer or Packagist. You must
obtain and install a Hive-compatible ODBC driver (and configure a system
DSN, or a DSN string per the `dsn` config key) yourself before this driver
can connect to anything.
