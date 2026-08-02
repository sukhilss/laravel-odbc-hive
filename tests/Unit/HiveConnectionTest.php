<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit;

use Illuminate\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Sukhil\Database\Hive\HiveConnection;
use Sukhil\Database\Hive\Schema\HiveSchemaBuilder;

final class HiveConnectionTest extends TestCase
{
    public function test_it_does_not_declare_its_own_constructor(): void
    {
        // The parent accepts \PDO|(\Closure(): \PDO). Declaring PDO $pdo here
        // fatals when Laravel passes a closure for a lazy connection.
        $constructor = (new ReflectionClass(HiveConnection::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertNotSame(
            HiveConnection::class,
            $constructor->getDeclaringClass()->getName(),
            'HiveConnection must not declare __construct.'
        );
    }

    public function test_default_query_grammar_is_configured_with_this_connection(): void
    {
        $connection = new HiveConnection(new PDO('sqlite::memory:'));

        $grammar = $connection->getQueryGrammar();

        $property = new ReflectionProperty($grammar, 'connection');
        $this->assertSame($connection, $property->getValue($grammar));
    }

    public function test_get_schema_builder_configures_schema_grammar_with_this_connection(): void
    {
        $connection = new HiveConnection(new PDO('sqlite::memory:'));

        $builder = $connection->getSchemaBuilder();

        $this->assertInstanceOf(HiveSchemaBuilder::class, $builder);

        // getSchemaBuilder() above already forced a default schema grammar to
        // be set when none existed, so by this point getSchemaGrammar() is
        // guaranteed non-null in this test's own call order (not merely by
        // Illuminate's non-nullable @var docblock, which PHPStan is reading).
        // An assertNotNull() here would be redundant, not a safety check.
        $grammar = $connection->getSchemaGrammar();

        $property = new ReflectionProperty($grammar, 'connection');
        $this->assertSame($connection, $property->getValue($grammar));
    }

    public function test_statement_returns_true_for_a_successful_statement_affecting_no_rows(): void
    {
        // PDO::exec() returns int(0), not false, for a successful DDL
        // statement that affects no rows (e.g. CREATE TABLE). A naive
        // (bool) cast of that 0 would read as failure; statement() must
        // report success by comparing against false instead.
        $connection = new HiveConnection(new PDO('sqlite::memory:'));

        $result = $connection->statement('CREATE TABLE t (id INTEGER)');

        $this->assertTrue($result);
    }

    public function test_statement_returns_true_while_pretending(): void
    {
        $connection = new HiveConnection(new PDO('sqlite::memory:'));
        $result = null;

        // Connection::pretend() types its callback as Closure(Connection): mixed;
        // narrowing the parameter to HiveConnection here would violate
        // contravariance even though the value passed in is always a
        // HiveConnection at runtime.
        $connection->pretend(function (Connection $connection) use (&$result): void {
            $result = $connection->statement('CREATE TABLE t (id INTEGER)');
        });

        $this->assertTrue($result);
    }
}
