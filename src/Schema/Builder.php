<?php

namespace Sukhil\Database\Hive\Schema;

use Closure;
use Illuminate\Database\Schema\Blueprint;

/**
 * Class Builder
 * @package Sukhil\Database\Hive\Schema
 */
class Builder extends \Illuminate\Database\Schema\Builder
{
    /**
     * Blueprint
     * @param string $table
     * @param Closure|null $callback
     * @return Blueprint|mixed|HiveBlueprint
     */
    protected function createBlueprint($table, Closure $callback = null)
    {
        if (isset($this->resolver)) {
            return call_user_func($this->resolver, $table, $callback);
        }

        return new HiveBlueprint($table, $callback);
    }
}
