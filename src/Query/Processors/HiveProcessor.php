<?php

namespace Sukhil\Database\Hive\Query\Processors;

use Exception;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;
use Sukhil\Database\Hive\HiveConnection;
use Sukhil\Database\Hive\Query\Grammars\HiveGrammar;

/**
 * Class HiveProcessor
 * @package Sukhil\Database\Hive\Query\Processors
 */
class HiveProcessor extends Processor
{
    /**
     * Process an "insert get ID" query.
     * @param Builder $query
     * @param string $sql
     * @param array $values
     * @param null $sequence
     * @return int|null
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        $query->getConnection()->insert($sql, $values);
        return is_array($values) ? reset($values) : null;
    }
}
