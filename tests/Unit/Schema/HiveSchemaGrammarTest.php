<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Sukhil\Database\Hive\Schema\Grammars\HiveSchemaGrammar;
use Sukhil\Database\Hive\Schema\HiveBlueprint;
use Sukhil\Database\Hive\Tests\Support\BlueprintFactory;

final class HiveSchemaGrammarTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function compile(callable $definition): array
    {
        // The grammar must receive a connection: on Laravel 12 the parent
        // constructor requires it, and wrapTable() reads the prefix from it.
        // The blueprint must be built against a connection already carrying
        // that grammar, because Laravel 12 captures it at construction time.
        $connection = BlueprintFactory::connection();
        $grammar = new HiveSchemaGrammar($connection);
        $connection->setSchemaGrammar($grammar);

        $blueprint = BlueprintFactory::make('sample_table', function (HiveBlueprint $table) use ($definition): void {
            $definition($table);
        }, $grammar);
        $blueprint->create();

        return BlueprintFactory::toSql($blueprint, $connection, $grammar);
    }

    public function test_it_maps_numeric_types(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->integer('a');
            $table->bigInteger('b');
            $table->smallInteger('c');
            $table->tinyInteger('d');
            $table->float('e');
            $table->double('f');
        });

        $this->assertStringContainsString('a int', $sql[0]);
        $this->assertStringContainsString('b bigint', $sql[0]);
        $this->assertStringContainsString('c smallint', $sql[0]);
        $this->assertStringContainsString('d tinyint', $sql[0]);
        $this->assertStringContainsString('e float', $sql[0]);
        $this->assertStringContainsString('f double', $sql[0]);
    }

    public function test_it_maps_string_types(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('a');
            $table->char('b', 10);
            $table->varChar('c', 100);
            $table->text('d');
        });

        $this->assertStringContainsString('a string', $sql[0]);
        $this->assertStringContainsString('b char(10)', $sql[0]);
        $this->assertStringContainsString('c varchar(100)', $sql[0]);
        $this->assertStringContainsString('d varchar(65535)', $sql[0]);
    }

    public function test_it_maps_temporal_and_misc_types(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->date('a');
            $table->timestamp('b');
            $table->dateTime('c');
            $table->boolean('d');
            $table->binary('e');
        });

        $this->assertStringContainsString('a date', $sql[0]);
        $this->assertStringContainsString('b timestamp', $sql[0]);
        $this->assertStringContainsString('c timestamp', $sql[0]);
        $this->assertStringContainsString('d boolean', $sql[0]);
        $this->assertStringContainsString('e binary', $sql[0]);
    }

    public function test_it_maps_type_aliases_not_covered_by_sibling_assertions(): void
    {
        // typeMediumText, typeLongText, typeMediumInteger, typeNumeric and
        // typeDecimal each delegate to (or parallel) a sibling type. Exercise
        // them directly rather than assuming coverage from that sibling.
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->mediumText('a');
            $table->longText('b');
            $table->mediumInteger('c');
            // Blueprint exposes no fluent numeric() helper in this Laravel
            // version (unlike decimal()); use addColumn() to reach typeNumeric.
            $table->addColumn('numeric', 'd', ['total' => 8, 'places' => 2]);
            $table->decimal('e', 8, 2);
        });

        $this->assertStringContainsString('a varchar(65535)', $sql[0]);
        $this->assertStringContainsString('b varchar(65535)', $sql[0]);
        $this->assertStringContainsString('c int', $sql[0]);
        $this->assertStringContainsString('d numeric(8, 2)', $sql[0]);
        $this->assertStringContainsString('e decimal(8, 2)', $sql[0]);
    }

    public function test_it_drops_unsupported_column_modifiers(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('a')->nullable();
            $table->integer('b')->default(7);
            $table->integer('c')->unsigned();
        });

        $this->assertStringNotContainsString('null', $sql[0]);
        $this->assertStringNotContainsString('default', $sql[0]);
        $this->assertStringNotContainsString('unsigned', $sql[0]);
    }

    public function test_it_does_not_wrap_identifiers(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('name');
        });

        $this->assertStringStartsWith('create table sample_table (', $sql[0]);
    }

    public function test_it_emits_stored_as_orc(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('name');
            $table->storedAs('ORC');
        });

        $this->assertStringContainsString('STORED AS ORC', $sql[0]);
    }

    public function test_it_emits_location(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('name');
            $table->location('/warehouse/sample');
        });

        $this->assertStringContainsString(" LOCATION '/warehouse/sample'", $sql[0]);
    }

    public function test_it_emits_only_one_row_format_clause(): void
    {
        // Hive permits a single ROW FORMAT clause. v6 emitted both the SerDe
        // and DELIMITED forms, producing DDL Hive rejects.
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('name');
            $table->charset('UTF-8');
            $table->delimiter(',');
        });

        $this->assertSame(1, substr_count($sql[0], 'ROW FORMAT'));
        $this->assertStringContainsString('ROW FORMAT SERDE', $sql[0]);
        $this->assertStringNotContainsString('DELIMITED', $sql[0]);
    }

    public function test_it_separates_the_column_list_from_table_options(): void
    {
        // v6 emitted ")ROW FORMAT SERDE" with no separating space.
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('name');
            $table->charset('UTF-8');
        });

        $this->assertStringContainsString(') ROW FORMAT SERDE', $sql[0]);
    }

    public function test_it_orders_clauses_per_hiveql(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('name');
            $table->delimiter(',');
            $table->storedAs('ORC');
            $table->location('/warehouse/sample');
        });

        $rowFormat = strpos($sql[0], 'ROW FORMAT');
        $storedAs = strpos($sql[0], 'STORED AS');
        $location = strpos($sql[0], 'LOCATION');

        $this->assertNotFalse($rowFormat);
        $this->assertNotFalse($storedAs);
        $this->assertNotFalse($location);
        $this->assertLessThan($storedAs, $rowFormat);
        $this->assertLessThan($location, $storedAs);
    }

    public function test_it_emits_row_format_delimited(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('name');
            $table->delimiter(',');
        });

        $this->assertStringContainsString("ROW FORMAT DELIMITED FIELDS TERMINATED BY ','", $sql[0]);
    }

    public function test_it_emits_serde_properties_for_charset(): void
    {
        $sql = $this->compile(function (HiveBlueprint $table): void {
            $table->string('name');
            $table->charset('UTF-8');
        });

        $this->assertStringContainsString('LazySimpleSerDe', $sql[0]);
        $this->assertStringContainsString("'serialization.encoding'='UTF-8'", $sql[0]);
    }

    public function test_compile_create_accepts_an_optional_third_argument(): void
    {
        // Guards the dual-version strategy. Laravel 11 passes a Connection
        // here; Laravel 12 does not.
        $method = new \ReflectionMethod(HiveSchemaGrammar::class, 'compileCreate');
        $third = $method->getParameters()[2] ?? null;

        $this->assertNotNull($third, 'compileCreate must accept a third parameter.');
        $this->assertTrue($third->isOptional(), 'The third parameter must be optional.');
    }
}
