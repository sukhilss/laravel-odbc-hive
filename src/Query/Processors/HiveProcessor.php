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
     * $values arrives via Builder::insertGetId() -> cleanBindings(), which
     * re-indexes through Collection::values() before returning, so it is
     * always a plain numeric list of bound scalars — never string-keyed.
     *
     * The parent Processor::processInsertGetId() carries `@return int` in its
     * own docblock; without an explicit `@return` here PHPStan inherits that
     * narrower type onto this override even though its native return type is
     * `mixed`, and believes this method can never return null. It can: see
     * the empty-$values branch below.
     *
     * @param  array<int, mixed>  $values
     * @return mixed the first inserted value, or null when $values is empty
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null): mixed
    {
        $query->getConnection()->insert($sql, $values);

        return $values === [] ? null : reset($values);
    }
}
