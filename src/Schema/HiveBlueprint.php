<?php

namespace Sukhil\Database\Hive\Schema;

use Closure;

/**
 * Class Builder
 *
 * @package Cooperl\Database\DB2\Schema
 */
class HiveBlueprint extends \Illuminate\Database\Schema\Blueprint
{
    /**
     * Create a new schema blueprint.
     *
     * @param string $table
     * @param \Closure|null $callback
     * @param string $prefix
     * @return void
     */
    public function __construct($table, Closure $callback = null, $prefix = '')
    {
        parent::__construct($table, $callback, $prefix);
    }

    /**
     * Create a new char column on the table.
     *
     * @param  string  $column
     * @param  int|null  $length
     * @return \Illuminate\Database\Schema\ColumnDefinition
     */
    public function varChar($column, $length = null)
    {
        $length = $length ?? 65535;
        return $this->addColumn('varChar', $column, compact('length'));
    }
}
