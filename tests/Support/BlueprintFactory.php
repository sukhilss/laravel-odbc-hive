<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Grammars\Grammar as SchemaGrammar;
use Illuminate\Database\SQLiteConnection;
use PDO;
use Sukhil\Database\Hive\Schema\HiveBlueprint;
use Sukhil\Database\Hive\Support\IlluminateVersion;

/**
 * Builds a HiveBlueprint under whichever Laravel version is installed.
 *
 * A bare `Illuminate\Database\Connection` never gets a schema grammar
 * assigned (its `getDefaultSchemaGrammar()` is a no-op), and Laravel 12's
 * `Blueprint::__construct` assigns `$connection->getSchemaGrammar()` into a
 * non-nullable `Grammar $grammar` property, so a bare Connection fatals with
 * a TypeError. `SQLiteConnection` does implement `getDefaultSchemaGrammar()`,
 * but it is only wired up by an explicit `useDefaultSchemaGrammar()` call
 * (normally done by Laravel's ConnectionFactory, not the constructor).
 */
final class BlueprintFactory
{
    public static function make(
        string $table,
        ?Closure $callback = null,
        ?SchemaGrammar $schemaGrammar = null,
    ): HiveBlueprint {
        if (IlluminateVersion::detect()->usesConnectionAwareSchemaApi()) {
            /** @phpstan-ignore-next-line Laravel 12 signature */
            return new HiveBlueprint(self::connection($schemaGrammar), $table, $callback);
        }

        /** @phpstan-ignore-next-line Laravel 11 signature */
        return new HiveBlueprint($table, $callback);
    }

    public static function connection(?SchemaGrammar $schemaGrammar = null): Connection
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));

        if ($schemaGrammar !== null) {
            $connection->setSchemaGrammar($schemaGrammar);
        } else {
            $connection->useDefaultSchemaGrammar();
        }

        return $connection;
    }

    /**
     * Compile a blueprint to SQL, absorbing the Laravel 11/12 toSql() signature
     * difference: Laravel 12's Blueprint::toSql() takes no arguments (the
     * blueprint already holds its connection and grammar), Laravel 11's takes
     * (Connection $connection, Grammar $grammar) explicitly.
     *
     * @return array<int, string>
     */
    public static function toSql(
        HiveBlueprint $blueprint,
        Connection $connection,
        SchemaGrammar $grammar,
    ): array {
        if (IlluminateVersion::detect()->usesConnectionAwareSchemaApi()) {
            /** @phpstan-ignore-next-line Laravel 12 signature */
            return $blueprint->toSql();
        }

        /** @phpstan-ignore-next-line Laravel 11 signature */
        return $blueprint->toSql($connection, $grammar);
    }
}
