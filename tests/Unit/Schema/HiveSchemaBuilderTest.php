<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Schema;

use Closure;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Sukhil\Database\Hive\Schema\Grammars\HiveSchemaGrammar;
use Sukhil\Database\Hive\Schema\HiveBlueprint;
use Sukhil\Database\Hive\Schema\HiveSchemaBuilder;
use Sukhil\Database\Hive\Support\IlluminateVersion;
use Sukhil\Database\Hive\Tests\Support\BlueprintFactory;

final class HiveSchemaBuilderTest extends TestCase
{
    private function builder(): HiveSchemaBuilder
    {
        // A connection carrying the Hive schema grammar. Avoids depending on
        // HiveConnection (Task 10) or the provider (Task 11).
        //
        // The grammar must be attached BEFORE the builder is constructed:
        // Illuminate's Builder::__construct does
        // `$this->grammar = $connection->getSchemaGrammar()`.
        $connection = BlueprintFactory::connection();
        $connection->setSchemaGrammar(new HiveSchemaGrammar($connection));

        return new HiveSchemaBuilder($connection);
    }

    private function createBlueprint(HiveSchemaBuilder $builder, string $table): mixed
    {
        $method = new ReflectionMethod($builder, 'createBlueprint');

        return $method->invoke($builder, $table, null);
    }

    public function test_it_creates_hive_blueprints_under_the_installed_laravel(): void
    {
        $blueprint = $this->createBlueprint($this->builder(), 'sample_table');

        $this->assertInstanceOf(HiveBlueprint::class, $blueprint);
        $this->assertSame('sample_table', $blueprint->getTable());
    }

    public function test_a_registered_resolver_takes_precedence(): void
    {
        $builder = $this->builder();
        $sentinel = $this->createBlueprint($builder, 'ignored');
        $expectedConnection = $builder->getConnection();

        // The resolver's argument shape is version-divergent (see
        // HiveSchemaBuilder::createBlueprint), so the closure registered here
        // must match whichever signature the actually-installed Laravel uses
        // — not assume Laravel 12's shape unconditionally. The dedicated
        // version-override test below covers the Laravel 11 shape in
        // isolation regardless of what is installed.
        if (IlluminateVersion::detect()->usesConnectionAwareSchemaApi()) {
            $builder->blueprintResolver(function (Connection $connection, string $table, ?Closure $callback) use ($sentinel, $expectedConnection): HiveBlueprint {
                $this->assertSame($expectedConnection, $connection);
                $this->assertSame('other_table', $table);

                return $sentinel;
            });
        } else {
            $builder->blueprintResolver(function (string $table, ?Closure $callback, string $prefix) use ($sentinel): HiveBlueprint {
                $this->assertSame('other_table', $table);
                $this->assertNull($callback);
                $this->assertIsString($prefix);

                return $sentinel;
            });
        }

        $this->assertSame($sentinel, $this->createBlueprint($builder, 'other_table'));
    }

    public function test_set_illuminate_version_overrides_detected_default(): void
    {
        $builder = $this->builder();

        // Create sentinel FIRST with the detected version (Laravel 12)
        $sentinel = $this->createBlueprint($builder, 'ignored');

        // NOW inject a fake version claiming Laravel 11
        $fakeVersion = new IlluminateVersion(false);
        $builder->setIlluminateVersion($fakeVersion);

        // Track which resolver signature path is taken by using a resolver
        // that accepts Laravel 11 signature (3 params with prefix string)
        $callCount = 0;
        $builder->blueprintResolver(function ($table, ?Closure $callback, string $prefix) use (&$callCount, $sentinel): HiveBlueprint {
            $callCount++;
            $this->assertSame('other_table', $table);
            $this->assertNull($callback);
            $this->assertIsString($prefix);

            return $sentinel;
        });

        $result = $this->createBlueprint($builder, 'other_table');
        $this->assertSame(1, $callCount, 'Resolver should be called once');
        $this->assertSame($sentinel, $result, 'Resolver should return the sentinel');
    }
}
