<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Connectors;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDO;

/**
 * Opens PDO_ODBC connections to Hive.
 */
class HiveConnector extends Connector implements ConnectorInterface
{
    /**
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidArgumentException
     */
    public function connect(array $config): PDO
    {
        $dsn = $this->getDsn($config);

        if ($dsn === null) {
            throw new InvalidArgumentException(
                'Hive DSN is not configured. Set the "dsn" key on the connection '
                .'(or the HIVE_DSN environment variable) to an ODBC DSN.'
            );
        }

        return $this->createConnection($dsn, $config, $this->getOptions($config));
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
