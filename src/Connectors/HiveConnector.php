<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Connectors;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Support\Str;
use PDO;

/**
 * Opens PDO_ODBC connections to Hive.
 */
class HiveConnector extends Connector implements ConnectorInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): PDO
    {
        return $this->createConnection(
            (string) $this->getDsn($config),
            $config,
            $this->getOptions($config)
        );
    }

    /**
     * Build the ODBC DSN, adding the odbc: scheme when absent.
     *
     * @param  array<string, mixed>  $config
     */
    protected function getDsn(array $config): ?string
    {
        $dsn = $config['dsn'] ?? null;

        if (! is_string($dsn) || $dsn === '') {
            return null;
        }

        return Str::startsWith($dsn, 'odbc') ? $dsn : "odbc:{$dsn}";
    }
}
