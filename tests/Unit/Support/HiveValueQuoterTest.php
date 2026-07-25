<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Sukhil\Database\Hive\Support\HiveValueQuoter;

final class HiveValueQuoterTest extends TestCase
{
    private HiveValueQuoter $quoter;

    protected function setUp(): void
    {
        $this->quoter = new HiveValueQuoter();
    }

    public function test_it_wraps_a_plain_string_in_single_quotes(): void
    {
        $this->assertSame("'Alice'", $this->quoter->quoteString('Alice'));
    }

    public function test_it_escapes_single_quotes(): void
    {
        $this->assertSame("'O\\'Brien'", $this->quoter->quoteString("O'Brien"));
    }

    public function test_it_escapes_backslashes(): void
    {
        $this->assertSame("'a\\\\b'", $this->quoter->quoteString('a\\b'));
    }

    public function test_it_does_not_double_escape_an_escaped_quote(): void
    {
        // Input is a literal backslash followed by a quote. The backslash must
        // become two backslashes and the quote must gain its own backslash,
        // rather than the replacement being re-processed.
        $this->assertSame("'\\\\\\''", $this->quoter->quoteString("\\'"));
    }

    public function test_it_escapes_control_characters(): void
    {
        $this->assertSame("'a\\nb'", $this->quoter->quoteString("a\nb"));
        $this->assertSame("'a\\rb'", $this->quoter->quoteString("a\rb"));
        $this->assertSame("'a\\tb'", $this->quoter->quoteString("a\tb"));
    }

    public function test_it_handles_an_empty_string(): void
    {
        $this->assertSame("''", $this->quoter->quoteString(''));
    }

    public function test_literal_renders_null_as_the_null_keyword(): void
    {
        $this->assertSame('NULL', $this->quoter->literal(null));
    }

    public function test_literal_renders_booleans_as_hive_keywords(): void
    {
        $this->assertSame('true', $this->quoter->literal(true));
        $this->assertSame('false', $this->quoter->literal(false));
    }

    public function test_literal_renders_numbers_without_quotes(): void
    {
        $this->assertSame('42', $this->quoter->literal(42));
        $this->assertSame('1.5', $this->quoter->literal(1.5));
    }

    public function test_literal_quotes_strings(): void
    {
        $this->assertSame("'x'", $this->quoter->literal('x'));
    }
}
