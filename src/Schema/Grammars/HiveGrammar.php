<?php

namespace Sukhil\Database\Hive\Schema\Grammars;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;

/**
 * Class HiveGrammar
 * @package Sukhil\Database\Hive\Schema\Grammars
 */
class HiveGrammar extends Grammar
{

    /**
     * The possible column modifiers.
     * @var array
     */
    protected $preModifiers = [];

    /**
     * The possible modifiers
     * @var array
     */
    protected $modifiers = [];

    /**
     * The possible column serials
     * @var array
     */
    protected $serials = [];

    /**
     * Wrap a single string in keyword identifiers.
     *
     * @param string $value
     *
     * @return string
     */
    protected function wrapValue($value)
    {
        if ($value === '*') {
            return $value;
        }

        return str_replace('"', '""', $value);
    }

    /**
     * Compile a create table command.
     *
     * @param \Illuminate\Database\Schema\Blueprint $blueprint
     * @param \Illuminate\Support\Fluent $command
     * @param \Illuminate\Database\Connection $connection
     *
     * @return string
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command, Connection $connection)
    {
        $columns = implode(', ', $this->getColumns($blueprint));
        $sql = 'create table ' . $this->wrapTable($blueprint);

        $sql .= " ($columns)";

        if (!empty($blueprint->charset)) {
            $sql .= "ROW FORMAT SERDE 'org.apache.hadoop.hive.serde2.lazy.LazySimpleSerDe' WITH SERDEPROPERTIES " .
                "('serialization.encoding'='{$blueprint->charset}','store.charset'='{$blueprint->charset}', 'retrieve.charset'='{$blueprint->charset}')";
        }

        if (!empty($blueprint->format) && $blueprint->format == 'ORC') {
            $sql .= " STORED AS ORC";
        }

        if (!empty($blueprint->delimiter)) {
            $sql .= " ROW FORMAT DELIMITED FIELDS TERMINATED BY '{$blueprint->delimiter}'";
        }

        if (!empty($blueprint->location)) {
            $sql .= " LOCATION  '{$blueprint->location}'";
        }

        return $sql;
    }

    /**
     * Create the column definition for a char type.
     *
     * Char types are similar to Varchar but they are fixed-length meaning that values shorter than
     * the specified length value are padded with spaces but trailing spaces are not important during comparisons.
     * The maximum length is fixed at 255.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeChar(Fluent $column)
    {
        return "char({$column->length})";
    }

    /**
     * Create the column definition for a string type.
     *
     * String literals can be expressed with either single quotes (') or double quotes (").
     * Hive uses C-style escaping within the strings.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeString(Fluent $column)
    {
        return "string";
    }

    /**
     * Create the column definition for a text type.
     *
     * Varchar types are created with a length specifier (between 1 and 65535), which defines the
     * maximum number of characters allowed in the character string.
     * If a string value being converted/assigned to a varchar value exceeds the length specifier,
     * the string is silently truncated.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeVarChar(Fluent $column)
    {
        $colLength = ($column->length ?? 65535);
        return "varchar($colLength)";
    }

    /**
     * Create the column definition for a text type.
     * No direct data type for "text" so casted to varchar
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeText(Fluent $column)
    {
        return $this->typeVarChar($column);
    }

    /**
     * Create the column definition for a medium text type.
     * No direct data type for "medium text" so casted to varchar
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeMediumText(Fluent $column)
    {
        return $this->typeVarChar($column);
    }

    /**
     * Create the column definition for a long text type.
     * No direct data type for "long text" so casted to varchar
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeLongText(Fluent $column)
    {
        return $this->typeVarChar($column);
    }

    /**
     * Create the column definition for a big integer type.
     * BIGINT (8-byte signed integer, from -9,223,372,036,854,775,808 to 9,223,372,036,854,775,807)
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeBigInteger(Fluent $column)
    {
        return 'bigint';
    }

    /**
     * Create the column definition for a integer type.
     * INT/INTEGER (4-byte signed integer, from -2,147,483,648 to 2,147,483,647)
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeInteger(Fluent $column)
    {
        return 'int';
    }

    /**
     * Create the column definition for a integer type.
     * TINYINT (1-byte signed integer, from -128 to 127)
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeTinyInteger(Fluent $column)
    {
        return 'tinyint';
    }

    /**
     * Create the column definition for a small integer type.
     * SMALLINT (2-byte signed integer, from -32,768 to 32,767)
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeSmallInteger(Fluent $column)
    {
        return 'smallint';
    }

    /**
     * Create the column definition for a numeric type.
     * NUMERIC (same as DECIMAL, starting with Hive 3.0.0)
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeNumeric(Fluent $column)
    {
        return "numeric({$column->total}, {$column->places})";
    }

    /**
     * Create the column definition for a float type.
     * FLOAT (4-byte single precision floating point number)
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeFloat(Fluent $column)
    {
        return 'float';
    }

    /**
     * Create the column definition for a double type.
     * DOUBLE (8-byte double precision floating point number)
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeDouble(Fluent $column)
    {
        if ($column->total && $column->places) {
            return "double({$column->total}, {$column->places})";
        }

        return 'double';
    }

    /**
     * Create the column definition for a decimal type.
     * Introduced in Hive 0.11.0 with a precision of 38 digits
     * Hive 0.13.0 introduced user-definable precision and scale
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeDecimal(Fluent $column)
    {
        return "decimal({$column->total}, {$column->places})";
    }

    /**
     * Create the column definition for a boolean type.
     * BOOLEAN
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeBoolean(Fluent $column)
    {
        return "boolean";
    }

    /**
     * Create the column definition for a date type.
     * DATE values describe a particular year/month/day, in the form YYYY-­MM-­DD. For example, DATE '2013-­01-­01'.
     * Dates were introduced in Hive 0.12.0 (HIVE-4055).
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeDate(Fluent $column)
    {
        return 'date';
    }

    /**
     * Create the column definition for a date-time type.
     * Supports traditional UNIX timestamp with optional nanosecond precision.
     *
     * Supported conversions:
     * -- Integer numeric types: Interpreted as UNIX timestamp in seconds
     * -- Floating point numeric types: Interpreted as UNIX timestamp in seconds with decimal precision
     * -- Strings: JDBC compliant java.sql.Timestamp format "YYYY-MM-DD HH:MM:SS.fffffffff" (9 decimal place precision)
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeDateTime(Fluent $column)
    {
        return $this->typeTimestamp($column);
    }

    /**
     * Create the column definition for a timestamp type.
     * Supports traditional UNIX timestamp with optional nanosecond precision.
     *
     * Supported conversions:
     * -- Integer numeric types: Interpreted as UNIX timestamp in seconds
     * -- Floating point numeric types: Interpreted as UNIX timestamp in seconds with decimal precision
     * -- Strings: JDBC compliant java.sql.Timestamp format "YYYY-MM-DD HH:MM:SS.fffffffff" (9 decimal place precision)
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeTimestamp(Fluent $column)
    {
        return 'timestamp';
    }

    /**
     * Create the column definition for a binary type.
     * BINARY (Note: Only available starting with Hive 0.8.0)
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeBinary(Fluent $column)
    {
        return 'binary';
    }
}
