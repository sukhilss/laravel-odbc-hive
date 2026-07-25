<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Query;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sukhil\Database\Hive\Query\Grammars\HiveQueryGrammar;
use Sukhil\Database\Hive\Query\Processors\HiveProcessor;
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

    /**
     * A connection carrying a non-empty table prefix.
     *
     * The prefix is read from the connection on both majors: Laravel 12's
     * grammars derive it from their connection, and Laravel 11's
     * Connection::withTablePrefix() copies this same value onto the grammar.
     */
    private function prefixedConnection(): Connection
    {
        $connection = BlueprintFactory::connection();
        $connection->setTablePrefix('pfx_');

        return $connection;
    }

    /**
     * A REAL query builder, not a mock.
     *
     * Mocking the builder and assigning `$builder->from` by hand exercises only
     * compileInsert(), which is how three defects in wrapValue()/wrapTable()
     * (raw identifier interpolation, a fatal on Expression tables, and a
     * silently dropped table prefix) survived thirteen rounds of review.
     *
     * @param  array<int, string>  $columns
     */
    private function realQuery(Connection $connection, array $columns = ['*']): Builder
    {
        return (new Builder($connection, new HiveQueryGrammar($connection), new HiveProcessor))
            ->select($columns);
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
            .'All rows in a batch insert must share the same columns.'
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
            .'All rows in a batch insert must share the same columns.'
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

    public function test_it_compiles_a_select_with_a_where_using_the_table_prefix(): void
    {
        $connection = $this->prefixedConnection();
        $query = $this->realQuery($connection)->from('events')->where('name', 'Alice');

        // The prefix must reach queries, not just DDL: migrations create
        // pfx_events, so a read of `events` would target a table that does not
        // exist. Identifiers stay unquoted — Hive's double quote delimits a
        // string literal, not an identifier.
        $this->assertSame('select * from pfx_events where name = ?', $query->toSql());
    }

    public function test_it_compiles_a_select_with_explicit_columns_and_a_join(): void
    {
        $connection = $this->prefixedConnection();
        $query = $this->realQuery($connection, ['events.name', 'venues.city'])
            ->from('events')
            ->join('venues', 'events.venue_id', '=', 'venues.id')
            ->orderBy('events.name');

        $this->assertSame(
            'select pfx_events.name, pfx_venues.city from pfx_events '
            .'inner join pfx_venues on pfx_events.venue_id = pfx_venues.id '
            .'order by pfx_events.name asc',
            $query->toSql()
        );
    }

    public function test_it_compiles_an_update_and_a_delete_using_the_table_prefix(): void
    {
        $connection = $this->prefixedConnection();
        $grammar = new HiveQueryGrammar($connection);
        $query = $this->realQuery($connection)->from('events')->where('id', 1);

        $this->assertSame(
            'update pfx_events set name = ? where id = ?',
            $grammar->compileUpdate($query, ['name' => 'Alice'])
        );
        $this->assertSame(
            'delete from pfx_events where id = ?',
            $grammar->compileDelete($query)
        );
    }

    public function test_it_compiles_an_insert_using_the_table_prefix(): void
    {
        $connection = $this->prefixedConnection();
        $query = $this->realQuery($connection)->from('events');

        $this->assertSame(
            "insert into pfx_events (name) values ('Alice')",
            (new HiveQueryGrammar($connection))->compileInsert($query, ['name' => 'Alice'])
        );
    }

    public function test_it_prefixes_only_the_table_of_a_schema_qualified_name(): void
    {
        $connection = $this->prefixedConnection();
        $query = $this->realQuery($connection)->from('analytics.events');

        $this->assertSame('select * from analytics.pfx_events', $query->toSql());
    }

    public function test_it_rejects_an_unsafe_identifier_used_as_an_insert_column(): void
    {
        // Array keys are attacker-controlled in ->insert($request->all()). This
        // key previously escaped the column list entirely and emitted
        //   insert into events (name) values (@@x --) values ('y')
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Unsafe Hive identifier 'name) values (@@x --': "
            .'only letters, digits and underscores are permitted.'
        );

        $connection = $this->prefixedConnection();

        (new HiveQueryGrammar($connection))->compileInsert(
            $this->realQuery($connection)->from('events'),
            ['name) values (@@x --' => 'y']
        );
    }

    public function test_it_rejects_an_unsafe_identifier_used_as_a_select_column(): void
    {
        // ->select($request->input('columns')) is the read-side equivalent.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Unsafe Hive identifier 'name from events; drop table users --': "
            .'only letters, digits and underscores are permitted.'
        );

        $this->realQuery($this->prefixedConnection(), ['name from events; drop table users --'])
            ->from('events')
            ->toSql();
    }

    public function test_it_rejects_an_unsafe_table_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Unsafe Hive identifier 'pfx_events; drop table users --': "
            .'only letters, digits and underscores are permitted.'
        );

        $this->realQuery($this->prefixedConnection())
            ->from('events; drop table users --')
            ->toSql();
    }

    public function test_it_compiles_an_expression_table_without_converting_it_to_a_string(): void
    {
        // Laravel 10 dropped Expression::__toString(), so casting the table
        // fataled outright on fromRaw()/fromSub()/joinSub().
        $query = $this->realQuery(BlueprintFactory::connection())->fromRaw('(select 1) as t');

        $this->assertSame('select * from (select 1) as t', $query->toSql());
    }

    public function test_it_never_calls_pdo_quote(): void
    {
        // PDO_ODBC does not implement quote(); it returns false. Guard against
        // any future reintroduction of a PDO dependency in this class.
        $source = file_get_contents(
            __DIR__.'/../../../src/Query/Grammars/HiveQueryGrammar.php'
        );

        $this->assertIsString($source);
        $this->assertStringNotContainsString('->quote(', $source);
        $this->assertStringNotContainsString('getPdo', $source);
    }
}
