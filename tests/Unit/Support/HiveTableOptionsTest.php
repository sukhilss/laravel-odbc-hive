<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Sukhil\Database\Hive\Support\HiveTableOptions;

final class HiveTableOptionsTest extends TestCase
{
    public function test_all_options_default_to_null(): void
    {
        $options = new HiveTableOptions();

        $this->assertNull($options->charset());
        $this->assertNull($options->storedAs());
        $this->assertNull($options->delimiter());
        $this->assertNull($options->location());
    }

    public function test_setters_are_fluent_and_store_values(): void
    {
        $options = (new HiveTableOptions())
            ->setCharset('UTF-8')
            ->setStoredAs('ORC')
            ->setDelimiter(',')
            ->setLocation('/warehouse/events');

        $this->assertSame('UTF-8', $options->charset());
        $this->assertSame('ORC', $options->storedAs());
        $this->assertSame(',', $options->delimiter());
        $this->assertSame('/warehouse/events', $options->location());
    }

    public function test_it_reports_whether_any_option_is_set(): void
    {
        $this->assertTrue((new HiveTableOptions())->isEmpty());
        $this->assertFalse((new HiveTableOptions())->setCharset('UTF-8')->isEmpty());
        $this->assertFalse((new HiveTableOptions())->setStoredAs('ORC')->isEmpty());
        $this->assertFalse((new HiveTableOptions())->setDelimiter(',')->isEmpty());
        $this->assertFalse((new HiveTableOptions())->setLocation('/w')->isEmpty());
    }
}
