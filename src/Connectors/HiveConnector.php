<?php

namespace Sukhil\Database\Hive\Connectors;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Support\Str;

/**
 * Class HiveConnector
 * @package Sukhil\Database\Hive\Connectors
 */
class HiveConnector extends Connector implements ConnectorInterface
{
    /**
     * Create a connection for hive
     * @param array $config
     * @return \PDO
     * @throws \Exception
     */
    public function connect(array $config)
    {
        return $this->createConnection(
            $this->getDsn($config), $config, $this->getOptions($config)
        );
    }

    /**
     * Create a DSN string from the configuration.
     * @param array $config
     * @return mixed|string|null
     */
    protected function getDsn(array $config)
    {
        $dsn = $config['dsn'] ?? null;

        // Check whether string contains odbc or not
        if (!Str::startsWith($dsn, 'odbc') && !empty($dsn)) {
            $dsn = "odbc:{$dsn}";
        }

        return $dsn;
    }
}
