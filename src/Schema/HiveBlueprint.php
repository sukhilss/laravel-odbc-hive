<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Sukhil\Database\Hive\Support\HiveTableOptions;

/**
 * Blueprint with Hive-specific column types and table options.
 *
 * Deliberately declares no constructor: Blueprint::__construct differs between
 * Laravel 11 ($table, $callback, $prefix) and Laravel 12 (Connection, $table,
 * $callback). Not overriding it lets one class serve both. Construction is
 * handled by HiveSchemaBuilder.
 */
class HiveBlueprint extends Blueprint
{
    private ?HiveTableOptions $hiveOptions = null;

    /**
     * Create a varchar column. Hive allows 1 to 65535 characters; values
     * exceeding the limit are silently truncated by Hive itself.
     */
    public function varChar(string $column, ?int $length = null): ColumnDefinition
    {
        return $this->addColumn('varChar', $column, [
            'length' => $length ?? 65535,
        ]);
    }

    /**
     * Set the storage format, for example 'ORC'.
     */
    public function storedAs(string $format): self
    {
        $this->hiveOptions()->setStoredAs($format);

        return $this;
    }

    /**
     * Set the HDFS location backing this table.
     */
    public function location(string $path): self
    {
        $this->hiveOptions()->setLocation($path);

        return $this;
    }

    /**
     * Set the field delimiter for ROW FORMAT DELIMITED.
     */
    public function delimiter(string $delimiter): self
    {
        $this->hiveOptions()->setDelimiter($delimiter);

        return $this;
    }

    /**
     * Set the serialization charset, emitted as SerDe properties.
     *
     * The parent Blueprint::charset() takes an untyped $charset parameter (it
     * sets the table's CHARACTER SET for MySQL-style grammars) and declares no
     * return type. Overriding it with a typed parameter is forbidden by PHP's
     * signature-compatibility rules for method overrides, so this override
     * keeps the parameter untyped to remain compatible while still narrowing
     * the return type to self for fluent chaining, which PHP does permit.
     * The parent implementation is still called so the inherited $charset
     * property stays in sync for any code that reads it directly.
     *
     * @param  string  $charset
     */
    public function charset($charset): self
    {
        parent::charset($charset);

        $this->hiveOptions()->setCharset($charset);

        return $this;
    }

    public function hiveOptions(): HiveTableOptions
    {
        return $this->hiveOptions ??= new HiveTableOptions;
    }
}
