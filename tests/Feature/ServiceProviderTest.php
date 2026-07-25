<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Sukhil\Database\Hive\Connectors\HiveConnector;
use Sukhil\Database\Hive\HiveConnection;
use Sukhil\Database\Hive\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_it_binds_the_hive_connector(): void
    {
        $this->assertTrue($this->app->bound('db.connector.hive'));
        $this->assertInstanceOf(HiveConnector::class, $this->app->make('db.connector.hive'));
    }

    public function test_it_registers_a_connection_resolver_for_hive(): void
    {
        $this->assertNotNull(Connection::getResolver('hive'));
    }

    public function test_it_merges_package_config_without_publishing(): void
    {
        $this->assertIsArray(config('hive.connections'));
        $this->assertArrayHasKey('hive', config('hive.connections'));
    }

    public function test_the_shipped_connection_declares_the_hive_driver(): void
    {
        // Before v7 the published config omitted this key, so the package's own
        // default connection could never be resolved as published.
        $this->assertSame('hive', config('hive.connections.hive.driver'));
    }

    public function test_application_config_takes_precedence_over_package_defaults(): void
    {
        $this->assertSame(
            'overridden',
            config('database.connections.hive.database')
        );
    }

    /**
     * Proves the binding and resolver work end to end: resolving the 'hive'
     * connection through the database manager must yield a real
     * HiveConnection, not merely that a resolver/binding exists in the
     * container. The underlying PDO is lazily resolved by Laravel (wrapped
     * in a Closure by ConnectionFactory::createPdoResolver), so obtaining the
     * connection object does not open a real ODBC connection -- only calling
     * getPdo() or running a query would.
     */
    public function test_the_hive_connection_resolves_end_to_end(): void
    {
        $connection = DB::connection('hive');

        $this->assertInstanceOf(HiveConnection::class, $connection);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.hive', [
            'driver' => 'hive',
            'dsn' => 'odbc:Driver=Fake;Host=localhost',
            'database' => 'overridden',
            'prefix' => '',
        ]);
    }
}
