<?php

namespace Sukhil\Database\Hive\Query\Grammars;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Arr;

/**
 * Class HiveGrammar
 * @package Sukhil\Database\Hive\Query\Grammars
 */
class HiveGrammar extends Grammar
{
    /**
     * Compile an insert statement into SQL.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param array $values
     * @return string
     */
    public function compileInsert(Builder $query, array $values)
    {
        // Essentially we will force every insert to be treated as a batch insert which
        // simply makes creating the SQL easier for us since we can utilize the same
        // basic routine regardless of an amount of records given to us to insert.
        $table = $this->wrapTable($query->from);

        if (empty($values)) {
            return "insert into {$table} default values";
        }

        if (!is_array(reset($values))) {
            $values = [$values];
        }

        $columns = $this->columnize(array_keys(reset($values)));
        $data = $this->parseInsertValuesToSQL($values);

        return "insert into $table ($columns) values $data";
    }

    /**
     * Convert array to sql string
     * @param $values
     * @return string
     */
    protected function parseInsertValuesToSQL($values)
    {
        if (Arr::isAssoc($values)) {
            return $this->parseInsertArrayToSQL($values);
        }

        return collect($values)->map(function ($item) {
            return $this->parseInsertValuesToSQL($item);
        })->implode(', ');
    }

    /**
     * Convert associative array to quoted string
     *
     * @param $array
     * @return string
     */
    protected function parseInsertArrayToSQL($array)
    {
        $escapedString = collect($array)->map(function ($item) {
            return is_string($item) ? \DB::getPdo()->quote($item) : $item;
        })->implode(', ');

        return "({$escapedString})";
    }

    /**
     * Wrap a table in keyword identifiers.
     *
     * @param \Illuminate\Database\Query\Expression|string $table
     * @return string
     */
    public function wrapTable($table)
    {
        return $table;
    }
}
