<?php

namespace Sukhil\Database\Hive;

use Sukhil\Database\Hive\Connectors\HiveConnector;
use Illuminate\Support\ServiceProvider;

/**
 * Class HiveServiceProvider
 * @package Sukhil\Database\Hive
 */
class HiveServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

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
        // get the configs
        $conns = is_array(config('hive.connections')) ? config('hive.connections') : [];

        // Add my database configurations to the default set of configurations
        config(['database.connections' => array_merge($conns, config('database.connections'))]);

        // Extend the connections with pdo_odbc and pdo_ibm drivers
        foreach (config('database.connections') as $conn => $config) {
            // Only use configurations that feature a "odbc", "ibm" or "odbczos" driver
            if (!isset($config['driver']) || !in_array($config['driver'], ['hive'])) {
                continue;
            }

            // Create a connector
            $this->app['db']->extend($conn, function($config, $name) {
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
