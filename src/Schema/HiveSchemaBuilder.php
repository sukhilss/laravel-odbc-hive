<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Schema;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Sukhil\Database\Hive\Support\IlluminateVersion;

/**
 * Schema builder producing HiveBlueprint instances.
 *
 * One of only two classes in this package permitted to branch on the installed
 * Laravel version: Blueprint::__construct takes (Connection, $table, $callback)
 * on Laravel 12 and ($table, $callback, $prefix) on Laravel 11.
 */
class HiveSchemaBuilder extends Builder
{
    private ?IlluminateVersion $illuminateVersion = null;

    public function setIlluminateVersion(IlluminateVersion $version): self
    {
        $this->illuminateVersion = $version;

        return $this;
    }

    protected function illuminateVersion(): IlluminateVersion
    {
        return $this->illuminateVersion ??= IlluminateVersion::detect();
    }

    /**
     * Create a blueprint using whichever constructor the installed Laravel declares.
     */
    protected function createBlueprint($table, ?Closure $callback = null): HiveBlueprint
    {
        if (isset($this->resolver)) {
            // The resolver's argument shape is version-divergent, and this is a
            // documented public extension point — a user's resolver is written
            // against whichever Laravel they run, so call it the way their
            // framework would.
            if ($this->illuminateVersion()->usesConnectionAwareSchemaApi()) {
                /** @var HiveBlueprint */
                return call_user_func($this->resolver, $this->connection, $table, $callback);
            }

            $prefix = $this->connection->getConfig('prefix_indexes')
                ? $this->connection->getConfig('prefix')
                : '';

            /** @var HiveBlueprint */
            return call_user_func($this->resolver, $table, $callback, $prefix);
        }

        if ($this->illuminateVersion()->usesConnectionAwareSchemaApi()) {
            /** @phpstan-ignore-next-line Laravel 12 signature */
            return new HiveBlueprint($this->connection, $table, $callback);
        }

        /** @phpstan-ignore-next-line Laravel 11 signature */
        return new HiveBlueprint($table, $callback);
    }
}
