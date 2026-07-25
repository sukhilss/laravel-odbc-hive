<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive;

use Illuminate\Database\Connection;
use Sukhil\Database\Hive\Query\Grammars\HiveQueryGrammar;
use Sukhil\Database\Hive\Query\Processors\HiveProcessor;
use Sukhil\Database\Hive\Schema\Grammars\HiveSchemaGrammar;
use Sukhil\Database\Hive\Schema\HiveSchemaBuilder;
use Sukhil\Database\Hive\Support\IlluminateVersion;

/**
 * A Laravel database connection backed by Apache Hive over ODBC.
 *
 * Declares no constructor: the parent accepts \PDO|(\Closure(): \PDO), and
 * narrowing that in a child both violates contravariance and breaks lazy
 * connections.
 *
 * `configureGrammar()` is one of exactly FOUR sites in src/ permitted to branch
 * on the installed Laravel version; the others are
 * HiveSchemaBuilder::createBlueprint(), HiveQueryGrammar::__construct() and
 * HiveSchemaGrammar::__construct(). Two detection mechanisms are in use: this
 * site and the schema builder ask IlluminateVersion::usesConnectionAwareSchemaApi();
 * the two grammar constructors ask method_exists(parent::class, '__construct')
 * instead, because they must decide how to initialise their own parent before
 * that parent has been initialised, and the fact they need is specifically
 * whether their parent declares a constructor. IlluminateVersion holds the
 * authoritative note, including why one boolean stands in for six divergences.
 */
class HiveConnection extends Connection
{
    private ?IlluminateVersion $illuminateVersion = null;

    protected function illuminateVersion(): IlluminateVersion
    {
        return $this->illuminateVersion ??= IlluminateVersion::detect();
    }

    public function getSchemaBuilder(): HiveSchemaBuilder
    {
        if ($this->schemaGrammar === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new HiveSchemaBuilder($this);
    }

    protected function getDefaultQueryGrammar(): HiveQueryGrammar
    {
        return $this->configureGrammar(new HiveQueryGrammar($this));
    }

    protected function getDefaultSchemaGrammar(): HiveSchemaGrammar
    {
        return $this->configureGrammar(new HiveSchemaGrammar($this));
    }

    protected function getDefaultPostProcessor(): HiveProcessor
    {
        return new HiveProcessor();
    }

    /**
     * Apply the table prefix using whichever mechanism this Laravel provides.
     *
     * On Laravel 12 the grammar derives the prefix from the connection it was
     * constructed with, so there is nothing further to do. On Laravel 11 the
     * prefix is a separate property, set via the connection's withTablePrefix().
     *
     * @template TGrammar of object
     *
     * @param  TGrammar  $grammar
     * @return TGrammar
     */
    protected function configureGrammar(object $grammar): object
    {
        if ($this->illuminateVersion()->usesConnectionAwareSchemaApi()) {
            return $grammar;
        }

        /** @phpstan-ignore-next-line withTablePrefix exists only on Laravel 11 */
        return $this->withTablePrefix($grammar);
    }

    /**
     * Execute an SQL statement and return its result.
     *
     * Uses PDO::exec rather than prepare: the Hive ODBC driver does not support
     * prepared DDL statements.
     *
     * PDO::exec() returns the number of affected rows on success (0 for a DDL
     * statement such as CREATE TABLE, which affects none) or false on failure.
     * The result is therefore compared against false explicitly rather than
     * cast with (bool) — an unconditional cast would turn a successful
     * zero-row DDL statement into a false, misreporting it as a failure.
     *
     * @param  array<mixed>  $bindings
     */
    public function statement($query, $bindings = []): bool
    {
        return $this->run($query, $bindings, function (string $query): bool {
            if ($this->pretending()) {
                return true;
            }

            return $this->getPdo()->exec($query) !== false;
        });
    }
}
