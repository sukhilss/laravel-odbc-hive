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
     * The name of the default schema.
     *
     * @var string
     */
    protected $defaultSchema;

    /**
     * The name of the current schema in use.
     *
     * @var string
     */
    protected $currentSchema;

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
        $this->currentSchema = $this->defaultSchema = config['schema'] ?? null;
    }

    /**
     * Reset to default the current schema.
     */
    public function resetCurrentSchema()
    {
        $this->setCurrentSchema($this->getDefaultSchema());
    }

    /**
     * Set the name of the current schema.
     * @param $schema
     */
    public function setCurrentSchema($schema)
    {
        $this->statement('SET SCHEMA ?', [strtoupper($schema)]);
    }

    /**
     * Get the name of the default schema.
     * @return string
     */
    public function getDefaultSchema()
    {
        return $this->defaultSchema;
    }

    /**
     * Execute a system command.
     * @param $command
     */
    public function executeCommand($command)
    {
        $this->statement('CALL QSYS2.QCMDEXC(?)', [$command]);
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
        $defaultGrammar = new QueryGrammar;

        if (array_key_exists('date_format', $this->config)) {
            $defaultGrammar->setDateFormat($this->config['date_format']);
        }

        return $this->withTablePrefix($defaultGrammar);
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
     * @return HiveProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new HiveProcessor;
    }
}
