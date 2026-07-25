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
