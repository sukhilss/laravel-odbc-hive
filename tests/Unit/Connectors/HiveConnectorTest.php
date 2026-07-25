<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Connectors;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Sukhil\Database\Hive\Connectors\HiveConnector;

final class HiveConnectorTest extends TestCase
{
    private function dsn(array $config): ?string
    {
        $method = new ReflectionMethod(HiveConnector::class, 'getDsn');

        return $method->invoke(new HiveConnector, $config);
    }

    public function test_it_prefixes_a_bare_dsn_with_odbc(): void
    {
        $this->assertSame(
            'odbc:Driver=Hive;Host=localhost',
            $this->dsn(['dsn' => 'Driver=Hive;Host=localhost'])
        );
    }

    public function test_it_leaves_an_already_prefixed_dsn_alone(): void
    {
        $this->assertSame(
            'odbc:Driver=Hive',
            $this->dsn(['dsn' => 'odbc:Driver=Hive'])
        );
    }

    public function test_it_returns_null_for_a_missing_dsn(): void
    {
        $this->assertNull($this->dsn([]));
    }

    public function test_it_returns_null_for_an_empty_dsn(): void
    {
        $this->assertNull($this->dsn(['dsn' => '']));
    }
}
