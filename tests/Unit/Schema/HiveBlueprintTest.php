<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Sukhil\Database\Hive\Schema\HiveBlueprint;
use Sukhil\Database\Hive\Tests\Support\BlueprintFactory;

final class HiveBlueprintTest extends TestCase
{
    public function test_it_does_not_declare_its_own_constructor(): void
    {
        // Guards the compatibility strategy: the parent constructor signature
        // differs between Laravel 11 and 12, so overriding it would break one.
        $constructor = (new ReflectionClass(HiveBlueprint::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertNotSame(
            HiveBlueprint::class,
            $constructor->getDeclaringClass()->getName(),
            'HiveBlueprint must not declare __construct.'
        );
    }

    public function test_var_char_defaults_to_the_hive_maximum_length(): void
    {
        $blueprint = BlueprintFactory::make('sample');
        $column = $blueprint->varChar('name');

        $this->assertSame('varChar', $column->get('type'));
        $this->assertSame(65535, $column->get('length'));
    }

    public function test_var_char_accepts_an_explicit_length(): void
    {
        $blueprint = BlueprintFactory::make('sample');
        $column = $blueprint->varChar('name', 120);

        $this->assertSame(120, $column->get('length'));
    }

    public function test_var_char_registers_the_column_on_the_blueprint(): void
    {
        // Guards against a varChar() that builds a ColumnDefinition but never
        // actually adds it to the blueprint's column list.
        $blueprint = BlueprintFactory::make('sample');
        $blueprint->varChar('name', 120);

        $columns = $blueprint->getColumns();

        $this->assertCount(1, $columns);
        $this->assertSame('name', $columns[0]->get('name'));
        $this->assertSame('varChar', $columns[0]->get('type'));
        $this->assertSame(120, $columns[0]->get('length'));
    }

    public function test_table_option_methods_are_fluent_and_recorded(): void
    {
        $blueprint = BlueprintFactory::make('sample');

        $result = $blueprint
            ->storedAs('ORC')
            ->location('/warehouse/sample')
            ->delimiter(',')
            ->charset('UTF-8');

        $this->assertSame($blueprint, $result);
        $this->assertSame('ORC', $blueprint->hiveOptions()->storedAs());
        $this->assertSame('/warehouse/sample', $blueprint->hiveOptions()->location());
        $this->assertSame(',', $blueprint->hiveOptions()->delimiter());
        $this->assertSame('UTF-8', $blueprint->hiveOptions()->charset());
    }

    public function test_hive_options_returns_the_same_instance_on_repeated_calls(): void
    {
        // Guards the lazy-initialisation: if hiveOptions() allocated a fresh
        // HiveTableOptions on every call, the fluent setters above would
        // silently write into an instance nobody reads back.
        $blueprint = BlueprintFactory::make('sample');

        $first = $blueprint->hiveOptions();
        $second = $blueprint->hiveOptions();

        $this->assertSame($first, $second);
    }
}
