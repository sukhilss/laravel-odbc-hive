<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Query;

use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sukhil\Database\Hive\Query\Grammars\HiveQueryGrammar;
use Sukhil\Database\Hive\Tests\Support\BlueprintFactory;

final class HiveQueryGrammarTest extends TestCase
{
    private HiveQueryGrammar $grammar;

    protected function setUp(): void
    {
        // Laravel 12's parent Grammar constructor requires a connection.
        $this->grammar = new HiveQueryGrammar(BlueprintFactory::connection());
    }

    private function query(string $table): Builder
    {
        $builder = $this->createMock(Builder::class);
        $builder->from = $table;

        return $builder;
    }

    public function test_it_does_not_wrap_table_names(): void
    {
        $this->assertSame('events', $this->grammar->wrapTable('events'));
    }

    public function test_it_compiles_a_single_row_insert(): void
    {
        $sql = $this->grammar->compileInsert(
            $this->query('events'),
            ['name' => 'Alice', 'age' => 30]
        );

        $this->assertSame(
            "insert into events (name, age) values ('Alice', 30)",
            $sql
        );
    }

    public function test_it_compiles_a_batch_insert(): void
    {
        $sql = $this->grammar->compileInsert(
            $this->query('events'),
            [
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob', 'age' => 25],
            ]
        );

        $this->assertSame(
            "insert into events (name, age) values ('Alice', 30), ('Bob', 25)",
            $sql
        );
    }

    public function test_it_compiles_a_batch_insert_with_differing_key_order(): void
    {
        // compileInsert derives its column list from the first row only. If a
        // later row's keys are in a different order, values must still be
        // matched to columns by key, not by position, or they silently pair
        // with the wrong column.
        $sql = $this->grammar->compileInsert(
            $this->query('events'),
            [
                ['name' => 'Alice', 'age' => 30],
                ['age' => 25, 'name' => 'Bob'],
            ]
        );

        $this->assertSame(
            "insert into events (name, age) values ('Alice', 30), ('Bob', 25)",
            $sql
        );
    }

    public function test_it_throws_when_a_batch_row_is_missing_a_column(): void
    {
        // A missing key must not silently become NULL: it can execute
        // cleanly while writing wrong data, with no signal anything is
        // amiss.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Insert row 1 has mismatched columns: missing [age]. '
            . 'All rows in a batch insert must share the same columns.'
        );

        $this->grammar->compileInsert(
            $this->query('events'),
            [
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob'],
            ]
        );
    }

    public function test_it_throws_when_a_batch_row_has_an_unexpected_column(): void
    {
        // An extra key must not be silently dropped: it can execute cleanly
        // while discarding data, with no signal anything is amiss.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Insert row 1 has mismatched columns: unexpected [city]. '
            . 'All rows in a batch insert must share the same columns.'
        );

        $this->grammar->compileInsert(
            $this->query('events'),
            [
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob', 'age' => 25, 'city' => 'NYC'],
            ]
        );
    }

    public function test_it_compiles_an_insert_with_no_rows_as_default_values(): void
    {
        $sql = $this->grammar->compileInsert($this->query('events'), []);

        $this->assertSame('insert into events default values', $sql);
    }

    public function test_it_escapes_string_values(): void
    {
        $sql = $this->grammar->compileInsert(
            $this->query('events'),
            ['name' => "O'Brien"]
        );

        $this->assertSame("insert into events (name) values ('O\\'Brien')", $sql);
    }

    public function test_it_renders_null_as_the_null_keyword(): void
    {
        // v6 emitted an empty string here, producing malformed SQL.
        $sql = $this->grammar->compileInsert(
            $this->query('events'),
            ['name' => null]
        );

        $this->assertSame('insert into events (name) values (NULL)', $sql);
    }

    public function test_it_never_calls_pdo_quote(): void
    {
        // PDO_ODBC does not implement quote(); it returns false. Guard against
        // any future reintroduction of a PDO dependency in this class.
        $source = file_get_contents(
            __DIR__ . '/../../../src/Query/Grammars/HiveQueryGrammar.php'
        );

        $this->assertIsString($source);
        $this->assertStringNotContainsString('->quote(', $source);
        $this->assertStringNotContainsString('getPdo', $source);
    }
}
