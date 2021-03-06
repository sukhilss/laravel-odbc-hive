<?php

namespace Sukhil\Database\Hive\Schema;

use Closure;

/**
 * Class HiveBlueprint
 * @package Sukhil\Database\Hive\Schema
 */
class HiveBlueprint extends \Illuminate\Database\Schema\Blueprint
{
    /**
     * HiveBlueprint constructor.
     * @param $table
     * @param Closure|null $callback
     * @param string $prefix
     */
    public function __construct($table, Closure $callback = null, $prefix = '')
    {
        parent::__construct($table, $callback, $prefix);
    }

    /**
     * Create a new char column on the table.
     * @param $column
     * @param null $length
     * @return \Illuminate\Database\Schema\ColumnDefinition
     */
    public function varChar($column, $length = null)
    {
        $length = $length ?? 65535;
        return $this->addColumn('varChar', $column, compact('length'));
    }
}
