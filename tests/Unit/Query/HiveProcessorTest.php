<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Query;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use PHPUnit\Framework\TestCase;
use Sukhil\Database\Hive\Query\Processors\HiveProcessor;

final class HiveProcessorTest extends TestCase
{
    private HiveProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new HiveProcessor;
    }

    private function queryWithConnection(ConnectionInterface $connection): Builder
    {
        $builder = $this->createMock(Builder::class);
        $builder->method('getConnection')->willReturn($connection);

        return $builder;
    }

    public function test_it_returns_the_first_inserted_value_as_a_last_insert_id_stand_in(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('insert')
            ->with('insert into events (name, age) values (?, ?)', ['Alice', 30])
            ->willReturn(true);

        $id = $this->processor->processInsertGetId(
            $this->queryWithConnection($connection),
            'insert into events (name, age) values (?, ?)',
            ['Alice', 30]
        );

        // Hive has no sequences or last-insert-id; the first inserted value
        // stands in for it.
        $this->assertSame('Alice', $id);
    }

    public function test_it_returns_null_when_there_are_no_values(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('insert')
            ->with('insert into events default values', [])
            ->willReturn(true);

        $id = $this->processor->processInsertGetId(
            $this->queryWithConnection($connection),
            'insert into events default values',
            []
        );

        $this->assertNull($id);
    }
}
