<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Schema\Grammars;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;
use Sukhil\Database\Hive\Schema\HiveBlueprint;
use Sukhil\Database\Hive\Support\HiveIdentifier;
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
     *
     * This is one of exactly FOUR sites in src/ permitted to branch on the
     * installed Laravel version; the others are HiveConnection::configureGrammar(),
     * HiveSchemaBuilder::createBlueprint() and HiveQueryGrammar::__construct().
     * Two detection mechanisms are in use: those first two sites ask
     * IlluminateVersion::usesConnectionAwareSchemaApi(); this constructor and
     * HiveQueryGrammar's ask method_exists(parent::class, '__construct')
     * instead, because they must decide how to initialise their own parent
     * before that parent has been initialised, and the fact they need is
     * specifically whether their parent declares a constructor — which the
     * probe answers directly rather than by proxy. IlluminateVersion holds the
     * authoritative note, including why one boolean stands in for six
     * divergences.
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
     * Emit a DDL identifier verbatim, after proving it safe.
     *
     * Hive identifiers are not quoted here (a double quote delimits a string
     * literal in Hive, not an identifier), so the identifier IS the SQL and has
     * to be validated before it is emitted. DDL identifiers come from migration
     * source rather than request data, so the exposure is far smaller than on
     * the query side, but the guard belongs on both paths — and doubling an
     * embedded double quote, as this previously did, escaped nothing that Hive
     * would have treated as an escape.
     *
     * wrapTable() is intentionally NOT overridden: the base implementation
     * already routes every segment through this method and applies the table
     * prefix using whichever mechanism the installed major provides.
     */
    protected function wrapValue($value): string
    {
        if ($value === '*') {
            return $value;
        }

        return HiveIdentifier::assertSafe((string) $value);
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

        $sql = 'create table '.$this->wrapTable($blueprint)." ($columns)";

        return $sql.$this->compileTableOptions($blueprint);
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
            .$this->storedAsClause($options)
            .$this->locationClause($options);
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
                .' WITH SERDEPROPERTIES ('
                ."'serialization.encoding'='{$charset}', "
                ."'store.charset'='{$charset}', "
                ."'retrieve.charset'='{$charset}')";
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
        return 'varchar('.($column->get('length') ?? 65535).')';
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
