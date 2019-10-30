<?php

namespace Sukhil\Database\Hive\Connectors;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;

/**
 * Class IBMConnector
 *
 * @package Cooperl\Database\DB2\Connectors
 */
class HiveConnector extends Connector implements ConnectorInterface
{
    /**
     * @param array $config
     *
     * @return \PDO
     */
    public function connect(array $config)
    {
        $dsn = $config['dsn'] ?? null;
        $options = $this->getOptions($config);
        $connection = $this->createConnection($dsn, $config, $options);

        return $connection;
    }
}
