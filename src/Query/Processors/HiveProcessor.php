<?php

namespace Sukhil\Database\Hive\Query\Processors;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;
use Sukhil\Database\Hive\Query\Grammars\HiveGrammar;

/**
 * Class DB2Processor
 *
 * @package Cooperl\Database\DB2\Query\Processors
 */
class HiveProcessor extends Processor
{
    /**
     * Process an "insert get ID" query.
     *
     * @param  \Illuminate\Database\Query\Builder $query
     * @param  string                             $sql
     * @param  array                              $values
     * @param  string                             $sequence
     *
     * @return int/array
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        $query->getConnection()->insert($sql, $values);

        //$id = $query->getConnection()->getPdo()->lastInsertId($sequence);

        return null;
    }
}
