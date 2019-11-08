<?php

namespace Sukhil\Database\Hive;

use Illuminate\Database\Connection;
use PDO;
use Sukhil\Database\Hive\Query\Grammars\HiveGrammar as QueryGrammar;
use Sukhil\Database\Hive\Query\Processors\HiveProcessor;
use Sukhil\Database\Hive\Schema\Builder;
use Sukhil\Database\Hive\Schema\Grammars\HiveGrammar as SchemaGrammar;

/**
 * Class HiveConnection
 * @package Sukhil\Database\Hive
 */
class HiveConnection extends Connection
{
    /**
     * HiveConnection constructor.
     * @param PDO $pdo
     * @param string $database
     * @param string $tablePrefix
     * @param array $config
     */
    public function __construct(PDO $pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);
    }

    /**
     * Get a schema builder instance for the connection.
     * @return Builder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new Builder($this);
    }

    /**
     * get default query grammer
     * @return mixed
     */
    protected function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix(new QueryGrammar);
    }

    /**
     * Default grammar for specified Schema
     * @return mixed
     */
    protected function getDefaultSchemaGrammar()
    {
        return $this->withTablePrefix(new SchemaGrammar);
    }

    /**
     * Get the default post processor instance.
     * @return \Illuminate\Database\Query\Processors\Processor|HiveProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new HiveProcessor;
    }

    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param string $query
     * @param array $bindings
     * @return bool|mixed
     */
    public function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            return $this->getPdo()->exec($query);
        });
    }

}
