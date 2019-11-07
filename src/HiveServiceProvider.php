<?php

namespace Sukhil\Database\Hive;

use Illuminate\Support\ServiceProvider;
use Sukhil\Database\Hive\Connectors\HiveConnector;

/**
 * Class HiveServiceProvider
 * @package Sukhil\Database\Hive
 */
class HiveServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $configPath = __DIR__ . '/config/hive.php';
        $this->publishes([$configPath => $this->getConfigPath()], 'config');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // Get current database configurations
        $conns = is_array(config('hive.connections')) ? config('hive.connections') : [];

        // Add hive database configurations to the default set of configurations
        config(['database.connections' => array_merge($conns, config('database.connections'))]);

        // Extend the connections for hive driver
        foreach (config('database.connections') as $conn => $config) {
            if (empty($config['driver']) || $config['driver'] != 'hive') {
                continue;
            }

            // Create a connector for hive
            $this->app['db']->extend($conn, function ($config, $name) {
                $config['name'] = $name;
                $connector = new HiveConnector();
                $hiveConnection = $connector->connect($config);
                return new HiveConnection($hiveConnection, $config["database"], $config["prefix"], $config);
            });
        }
    }

    /**
     * Get the config path
     *
     * @return string
     */
    protected function getConfigPath()
    {
        return config_path('hive.php');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
