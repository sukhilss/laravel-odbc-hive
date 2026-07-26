# Schema Builder

`Schema::connection('hive')` is backed by three Hive-specific classes:
`HiveSchemaBuilder`, `HiveSchemaGrammar`, and `HiveBlueprint`
(`src/Schema/HiveSchemaBuilder.php`, `src/Schema/Grammars/HiveSchemaGrammar.php`,
`src/Schema/HiveBlueprint.php`). This page covers the column types Hive
supports, the four Hive-only table options `HiveBlueprint` adds, and a
worked, verified example of the DDL they produce.

Schema support is **CREATE-only** — `Schema::drop()`, `dropIfExists()`,
`rename()`, and adding columns via `Schema::table()` either silently do
nothing or throw, depending on which one you call. Column modifiers such as
`->nullable()`, `->default()`, and `->unsigned()` are also silently dropped,
since Hive has no equivalent constraints. Both of these are documented in
detail, with real command output, in [`limitations.md`](limitations.md) —
read it before writing migrations against this driver.

## Column type mapping

Every mapping below was produced by compiling a real `HiveBlueprint` through
`HiveSchemaGrammar` and reading the emitted `create table` statement — not
inferred from reading the grammar source. The exact compiled statement is
included further down, and the standalone script used to produce it is
reproduced in "How this was verified" at the bottom of this page.

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

`varChar()` (note the capital `C`) is not part of Illuminate's base
`Blueprint` — it's a Hive-specific addition described next. If you omit the
length, it defaults to `65535` (Hive's maximum), not an error:

```php
$table->varChar('c'); // varchar(65535)
```

## `HiveBlueprint`'s Hive-specific API

Beyond every column type Illuminate's `Blueprint` already provides,
`HiveBlueprint` (`src/Schema/HiveBlueprint.php`) adds:

```php
varChar(string $column, ?int $length = null): ColumnDefinition
storedAs(string $format): self
location(string $path): self
delimiter(string $delimiter): self
charset($charset): self
hiveOptions(): HiveTableOptions
```

- **`storedAs(string $format)`** — sets the storage format, e.g.
  `storedAs('ORC')`, emitted as `STORED AS ORC`.
- **`location(string $path)`** — sets the HDFS location backing the table,
  emitted as `LOCATION '...'`.
- **`delimiter(string $delimiter)`** — sets the field delimiter, emitted as
  `ROW FORMAT DELIMITED FIELDS TERMINATED BY '...'`.
- **`charset($charset)`** — sets a SerDe-driven serialization charset,
  emitted as `ROW FORMAT SERDE '...' WITH SERDEPROPERTIES (...)`.
  Hive permits exactly one `ROW FORMAT` clause per table: calling both
  `charset()` and `delimiter()` on the same blueprint does not error, but
  silently keeps the SerDe form and drops the delimiter. See "`charset()`
  silently wins over `delimiter()`" in [`limitations.md`](limitations.md) for
  the full demonstration.
- **`hiveOptions(): HiveTableOptions`** — the underlying value object
  (`src/Support/HiveTableOptions.php`) that the four methods above write to
  and that the grammar reads from when compiling `CREATE TABLE`. You will
  not normally call this directly; it exists because storing these as plain
  dynamic properties on the blueprint (the pre-v7 approach) relies on
  dynamic property creation, deprecated since PHP 8.2.

### The closure parameter must be type-hinted `HiveBlueprint`, not `Blueprint`

None of `varChar()`, `storedAs()`, `location()`, `delimiter()`, `charset()`,
or `hiveOptions()` exist on Illuminate's base `Blueprint` class. Type-hint
the migration closure's parameter as `HiveBlueprint`:

```php
use Sukhil\Database\Hive\Schema\HiveBlueprint;

Schema::connection('hive')->create('events', function (HiveBlueprint $table) {
    $table->string('name');
    $table->storedAs('ORC')->location('/warehouse/events');
});
```

To be precise about what "must" means here: `HiveSchemaBuilder` always
constructs and passes a real `HiveBlueprint` instance to the closure
regardless of how you type-hint the parameter, so calling `$table->storedAs()`
with the parameter hinted merely as `Blueprint` **does still work at
runtime** — PHP resolves method calls against the actual object, not the
declared parameter type. What the `HiveBlueprint` hint actually buys you is
static analysis and IDE support: this package is analysed with
Larastan/PHPStan at level 6 (`phpstan.neon`), and under a plain `Blueprint`
hint it reports both calls as undefined:

```
Call to an undefined method Illuminate\Database\Schema\Blueprint::storedAs().
Call to an undefined method Illuminate\Database\Schema\Blueprint::varChar().
```

Your IDE's autocomplete will also not offer these methods without the
correct hint. In short: use `HiveBlueprint` — it costs nothing and is what
makes the Hive-specific methods visible to tooling — but if you inherit a
migration written against the base `Blueprint` that happens to call one of
these methods anyway, it will still execute correctly.

## Worked example

```php
Schema::connection('hive')->create('events', function (HiveBlueprint $table) {
    $table->string('name');
    $table->storedAs('ORC')->location('/warehouse/events');
});
```

Compiling this blueprint (without a live Hive connection — schema
compilation is pure string generation, and no query is executed against a
server) produces exactly this DDL:

```sql
create table events (name string) STORED AS ORC LOCATION '/warehouse/events'
```

Clause order is fixed by the grammar regardless of the order you call the
builder methods in: `ROW FORMAT` (from `delimiter()`/`charset()`), then
`STORED AS`, then `LOCATION`, always follow the column list in that
sequence.

## Identifiers are not quoted

Table and column names are written into the generated SQL verbatim, not
wrapped in quotes — Hive's double quote delimits a string literal, not an
identifier, so this driver validates names instead
(`Sukhil\Database\Hive\Support\HiveIdentifier`, permitted pattern
`/^[A-Za-z0-9_]+\z/` per dot-separated segment). See "Identifiers must match
`[A-Za-z0-9_]`" in [`limitations.md`](limitations.md) for the exact
validation behaviour, including what happens with reserved words.

## How this was verified

The type-mapping table and the worked example above were both produced by
running the following script through `docker compose run --rm php php -r
'...'` against this repository's installed `illuminate/database` (^11/^12,
resolved to 12.64.0 in this Docker image), reusing the same
`BlueprintFactory` test helper the package's own test suite uses to build a
real, connected `HiveBlueprint`:

```php
require 'vendor/autoload.php';
use Sukhil\Database\Hive\Schema\Grammars\HiveSchemaGrammar;
use Sukhil\Database\Hive\Schema\HiveBlueprint;
use Sukhil\Database\Hive\Tests\Support\BlueprintFactory;

$connection = BlueprintFactory::connection();
$grammar = new HiveSchemaGrammar($connection);
$connection->setSchemaGrammar($grammar);

$blueprint = BlueprintFactory::make('events', function (HiveBlueprint $table): void {
    $table->string('name');
    $table->storedAs('ORC')->location('/warehouse/events');
}, $grammar);
$blueprint->create();

foreach (BlueprintFactory::toSql($blueprint, $connection, $grammar) as $s) {
    echo $s, "\n";
}
```

which printed:

```
create table events (name string) STORED AS ORC LOCATION '/warehouse/events'
```

No Hive server or ODBC driver was involved — schema compilation is pure PHP
string generation against an in-memory SQLite connection object (used only
to satisfy Laravel's `Connection`-aware `Blueprint` constructor; the SQL it
produces has nothing to do with SQLite).
