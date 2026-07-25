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

        $columnNames = array_keys($first);
        $columns = $this->columnize($columnNames);
        $rows = $this->compileRows($values, $columnNames);

        return "insert into {$table} ({$columns}) values {$rows}";
    }

    /**
     * @param  array<mixed>  $values
     * @param  array<int, string>  $columnNames  column order from the first
     *   row, used to keep every row's values aligned to that order even when
     *   a later row's own keys come in a different order
     */
    protected function compileRows(array $values, array $columnNames): string
    {
        if (Arr::isAssoc($values)) {
            return $this->compileRow($values, $columnNames);
        }

        return implode(', ', array_map(
            fn (array $row): string => $this->compileRow($row, $columnNames),
            $values
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $columnNames
     */
    protected function compileRow(array $row, array $columnNames): string
    {
        return '(' . implode(', ', array_map(
            fn (string $column): string => $this->quoter->literal($row[$column] ?? null),
            $columnNames
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

    /**
     * Hive column identifiers are used verbatim.
     *
     * The base Grammar::wrapValue() double-quotes every identifier
     * (`"name"`), which columnize() relies on for every column list. Hive
     * does not require quoted identifiers, and quoting them here would
     * produce SQL the fixture tests do not expect, so this mirrors the
     * verbatim-identifier policy already applied to wrapTable() above.
     *
     * @param  string  $value
     */
    protected function wrapValue($value): string
    {
        return $value;
    }
}
