<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Query\Processors;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;

/**
 * Post-processes Hive query results.
 */
class HiveProcessor extends Processor
{
    /**
     * Hive has no sequences or last-insert-id, so the first inserted value is
     * returned as the closest available stand-in.
     *
     * @param  array<string, mixed>  $values
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null): mixed
    {
        $query->getConnection()->insert($sql, $values);

        return $values === [] ? null : reset($values);
    }
}
