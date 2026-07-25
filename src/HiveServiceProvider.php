<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use Sukhil\Database\Hive\Connectors\HiveConnector;

/**
 * Registers the Hive database driver with Laravel.
 */
class HiveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'hive');

        // ConnectionFactory::createConnector() resolves this binding by name.
        $this->app->bind('db.connector.hive', HiveConnector::class);

        // ConnectionFactory::createConnection() consults registered resolvers first.
        Connection::resolverFor(
            'hive',
            fn ($pdo, $database, $prefix, $config): HiveConnection => new HiveConnection($pdo, $database, $prefix, $config)
        );
    }

    public function boot(): void
    {
        $this->publishes([$this->configPath() => config_path('hive.php')], 'hive-config');

        $this->registerPackageConnections();
    }

    /**
     * Expose the package's own connection definitions to the database manager.
     *
     * Application-level config wins: connections already defined in
     * database.connections are left untouched. Runs in boot() because the
     * database manager resolves connections lazily, well after boot.
     */
    protected function registerPackageConnections(): void
    {
        $packageConnections = config('hive.connections');

        if (! is_array($packageConnections)) {
            return;
        }

        config([
            'database.connections' => array_merge(
                $packageConnections,
                (array) config('database.connections', [])
            ),
        ]);
    }

    protected function configPath(): string
    {
        return __DIR__.'/../config/hive.php';
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return ['db.connector.hive'];
    }
}
