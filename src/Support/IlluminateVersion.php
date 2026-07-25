<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Detects which shape of the Illuminate schema API is installed.
 *
 * Laravel 12 changed Blueprint::__construct to take a Connection as its first
 * argument, and dropped the Connection argument from Grammar::compileCreate.
 * Laravel 11 retains the older signatures.
 *
 * This class is NOT the only place in the package that cares about the
 * difference — see the confinement note below. Each of the four sites carries a
 * short summary of it; this is the authoritative copy, and they must agree.
 *
 * ---------------------------------------------------------------------------
 * VERSION-BRANCH CONFINEMENT
 *
 * Exactly four sites in src/ may branch on the installed Laravel version:
 *
 *   1. HiveConnection::configureGrammar()      — table-prefix mechanism
 *   2. HiveSchemaBuilder::createBlueprint()    — Blueprint/resolver arguments
 *   3. HiveQueryGrammar::__construct()         — parent constructor vs setter
 *   4. HiveSchemaGrammar::__construct()        — parent constructor vs setter
 *
 * They use two different detection mechanisms, deliberately:
 *
 *   - Sites 1 and 2 call IlluminateVersion::usesConnectionAwareSchemaApi(),
 *     which reflects on Blueprint::__construct.
 *   - Sites 3 and 4 call method_exists(parent::class, '__construct'), because
 *     the question a grammar constructor must answer is a different one: not
 *     "which schema API shape is installed?" but "does my own parent declare a
 *     constructor I am obliged to call?". Probing the parent answers that
 *     directly rather than by proxy, and it is answerable at the only moment it
 *     can be asked — before parent::__construct() has run, when $this is not
 *     yet a fully initialised object of that parent.
 *
 * (tests/Support/BlueprintFactory.php branches too, but it is test scaffolding
 * standing in for the framework's own call sites, not package behaviour.)
 * ---------------------------------------------------------------------------
 *
 * CAUTION: usesConnectionAwareSchemaApi() is ONE boolean standing in for SIX
 * independent API divergences between Laravel 11 and 12:
 *
 *   1. Blueprint::__construct(Connection, $table, $callback) vs ($table, $callback, $prefix)
 *   2. Schema Grammar::__construct(Connection) vs setConnection()
 *   3. Grammar::compileCreate()'s trailing Connection argument (11 only)
 *   4. Blueprint::toSql() vs toSql(Connection, Grammar)
 *   5. The blueprint resolver's argument order and arity
 *   6. Where the table prefix lives: the grammar (11) or the connection (12)
 *
 * All six moved together in the 11 -> 12 transition, which is the only reason a
 * single probe is sound. The moment a third major diverges on any one of them
 * independently, this must be split into per-capability probes rather than
 * having a second meaning quietly attached to this boolean.
 */
final class IlluminateVersion
{
    public function __construct(
        private readonly bool $connectionAwareSchemaApi,
    ) {}

    /**
     * Probe a class by inspecting its constructor's first parameter type.
     *
     * Returns true if the first parameter is typed as Illuminate\Database\Connection.
     */
    public static function forClass(string $class): self
    {
        $parameters = (new ReflectionMethod($class, '__construct'))->getParameters();
        $firstParameter = $parameters[0] ?? null;
        $type = $firstParameter?->getType();

        return new self(
            $type instanceof ReflectionNamedType
                && is_a($type->getName(), Connection::class, true),
        );
    }

    /**
     * Probe the installed framework by inspecting Blueprint's constructor.
     */
    public static function detect(): self
    {
        return self::forClass(Blueprint::class);
    }

    /**
     * True on Laravel 12+, where Blueprint and the grammars receive a Connection.
     */
    public function usesConnectionAwareSchemaApi(): bool
    {
        return $this->connectionAwareSchemaApi;
    }
}
