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
 * `createBlueprint()` is one of exactly FOUR sites in src/ permitted to branch
 * on the installed Laravel version; the others are
 * HiveConnection::configureGrammar(), HiveQueryGrammar::__construct() and
 * HiveSchemaGrammar::__construct(). Two detection mechanisms are in use: this
 * site and the connection ask IlluminateVersion::usesConnectionAwareSchemaApi();
 * the two grammar constructors ask method_exists(parent::class, '__construct')
 * instead, because they must decide how to initialise their own parent before
 * that parent has been initialised, and the fact they need is specifically
 * whether their parent declares a constructor. IlluminateVersion holds the
 * authoritative note, including why one boolean stands in for six divergences.
 *
 * The divergence handled here: Blueprint::__construct takes
 * (Connection, $table, $callback) on Laravel 12 and ($table, $callback, $prefix)
 * on Laravel 11, and the blueprint resolver is called with the same shapes.
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
        // Illuminate types $resolver as a non-nullable Closure, so PHPStan
        // believes it is always set. It is genuinely uninitialised until
        // blueprintResolver() is called, which is exactly what this guards.
        /** @phpstan-ignore isset.property */
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

            // PHPStan reads Illuminate's Laravel 12 resolver signature
            // ($connection, $table, $callback) from the property docblock. This
            // branch only runs on Laravel 11, whose Builder calls the resolver
            // as ($table, $callback, $prefix) — verified against 11.x source.
            /**
             * @var HiveBlueprint
             * @phpstan-ignore argument.type, argument.type
             */
            return call_user_func($this->resolver, $table, $callback, $prefix);
        }

        // Same root cause as above: PHPStan believes $resolver is always set
        // (see the isset() guard), so it treats everything past the guard's
        // `if` block as unreachable. In reality $resolver is frequently unset
        // and execution falls through to here — verified by
        // HiveSchemaBuilderTest, which exercises both branches.
        /** @phpstan-ignore deadCode.unreachable */
        if ($this->illuminateVersion()->usesConnectionAwareSchemaApi()) {
            return new HiveBlueprint($this->connection, $table, $callback);
        }

        return new HiveBlueprint($table, $callback);
    }
}
