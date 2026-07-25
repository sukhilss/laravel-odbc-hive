<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Query\Grammars;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use InvalidArgumentException;
use Sukhil\Database\Hive\Support\HiveIdentifier;
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
     *
     * This is one of exactly FOUR sites in src/ permitted to branch on the
     * installed Laravel version; the others are HiveConnection::configureGrammar(),
     * HiveSchemaBuilder::createBlueprint() and HiveSchemaGrammar::__construct().
     * Two detection mechanisms are in use: those first two sites ask
     * IlluminateVersion::usesConnectionAwareSchemaApi(); this constructor and
     * HiveSchemaGrammar's ask method_exists(parent::class, '__construct')
     * instead, because they must decide how to initialise their own parent
     * before that parent has been initialised, and the fact they need is
     * specifically whether their parent declares a constructor — which the
     * probe answers directly rather than by proxy. IlluminateVersion holds the
     * authoritative note, including why one boolean stands in for six
     * divergences.
     */
    public function __construct(?Connection $connection = null, ?HiveValueQuoter $quoter = null)
    {
        $this->quoter = $quoter ?? new HiveValueQuoter;

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

        $columnNames = array_keys($first);
        $columns = $this->columnize($columnNames);
        $rows = $this->compileRows($values, $columnNames);

        return "insert into {$table} ({$columns}) values {$rows}";
    }

    /**
     * @param  array<mixed>  $values  always a list of rows: compileInsert()
     *                                normalises a single associative row into a one-element list before
     *                                calling this, so there is no bare-associative-row case to branch on
     * @param  array<int, string>  $columnNames  column order from the first
     *                                           row, used to keep every row's values aligned to that order even when
     *                                           a later row's own keys come in a different order
     */
    protected function compileRows(array $values, array $columnNames): string
    {
        return implode(', ', array_map(
            fn (array $row, int $index): string => $this->compileRow($row, $columnNames, $index),
            $values,
            array_keys($values)
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $columnNames
     */
    protected function compileRow(array $row, array $columnNames, int $index): string
    {
        $this->assertColumnsMatch($row, $columnNames, $index);

        return '('.implode(', ', array_map(
            fn (string $column): string => $this->quoter->literal($row[$column]),
            $columnNames
        )).')';
    }

    /**
     * Guard against a batch row whose keys don't match the first row's.
     *
     * Without this, a missing key would silently render as NULL and an
     * extra key would silently be dropped — both execute cleanly and write
     * wrong data with no signal anything is amiss, which is the wrong
     * failure mode for a class whose whole purpose is precise literal
     * emission.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $columnNames
     */
    private function assertColumnsMatch(array $row, array $columnNames, int $index): void
    {
        $missing = array_values(array_diff($columnNames, array_keys($row)));
        $unexpected = array_values(array_diff(array_keys($row), $columnNames));

        if ($missing === [] && $unexpected === []) {
            return;
        }

        $details = [];

        if ($missing !== []) {
            $details[] = 'missing ['.implode(', ', $missing).']';
        }

        if ($unexpected !== []) {
            $details[] = 'unexpected ['.implode(', ', $unexpected).']';
        }

        throw new InvalidArgumentException(sprintf(
            'Insert row %d has mismatched columns: %s. All rows in a batch insert must share the same columns.',
            $index,
            implode(', ', $details)
        ));
    }

    /**
     * Emit a table identifier verbatim, after proving it safe.
     *
     * The `$prefix` parameter is declared optional so this single override is
     * compatible with both parents: Laravel 11 declares `wrapTable($table)`,
     * Laravel 12 declares `wrapTable($table, $prefix = null)`. Omitting it
     * would be an incompatible declaration on Laravel 12 and fatal at load.
     *
     * This mirrors the algorithm of Laravel 12's base Grammar::wrapTable()
     * (Expression passthrough, then alias, then schema-qualified name, then the
     * plain case) but validates each segment instead of quoting it. It is
     * reimplemented rather than delegated for two reasons: the base method
     * reads the prefix from a different place on each major, and delegating
     * would make the emitted SQL for a prefixed, schema-qualified table depend
     * on which major is installed. Reading the prefix from the connection —
     * which both majors expose identically as Connection::getTablePrefix() —
     * keeps one code path and one output.
     *
     * The prefix is applied exactly once, here. It is deliberately NOT read
     * from the grammar's own tablePrefix property (Laravel 11) nor via
     * parent::wrapTable() (which would apply it a second time on either major).
     *
     * @param  Expression|string  $table
     * @param  string|null  $prefix
     */
    public function wrapTable($table, $prefix = null): string
    {
        if ($this->isExpression($table)) {
            // Laravel 10 dropped Expression::__toString(); fromRaw(), fromSub()
            // and joinSub() all put an Expression here, so the value has to be
            // unwrapped rather than cast.
            return (string) $this->getValue($table);
        }

        $prefix ??= $this->connection?->getTablePrefix() ?? '';
        $table = (string) $table;

        if (stripos($table, ' as ') !== false) {
            $segments = preg_split('/\s+as\s+/i', $table, 2);

            if (is_array($segments) && count($segments) === 2) {
                return $this->wrapTable($segments[0], $prefix)
                    .' as '.HiveIdentifier::assertSafe($prefix.$segments[1]);
            }
        }

        if (str_contains($table, '.')) {
            // The prefix belongs to the table, not to the schema that qualifies
            // it, so it is spliced in front of the final segment.
            $qualified = substr_replace($table, '.'.$prefix, (int) strrpos($table, '.'), 1);

            return implode('.', array_map(
                static fn (string $segment): string => HiveIdentifier::assertSafe($segment),
                explode('.', $qualified)
            ));
        }

        return HiveIdentifier::assertSafe($prefix.$table);
    }

    /**
     * Emit a column identifier verbatim, after proving it safe.
     *
     * The base Grammar::wrapValue() double-quotes every identifier (`"name"`),
     * which columnize() relies on for every column list. In Hive a double quote
     * delimits a string literal rather than an identifier, so that output is
     * wrong here — but simply returning the value removed the only escaping on
     * the path between an attacker-controlled array key and emitted SQL. The
     * identifier is therefore validated instead of quoted: legitimate names are
     * emitted byte-identically, and anything else throws.
     *
     * @param  string  $value
     */
    protected function wrapValue($value): string
    {
        return $value === '*' ? $value : HiveIdentifier::assertSafe((string) $value);
    }
}
