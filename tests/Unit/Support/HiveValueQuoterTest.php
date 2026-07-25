<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Stringable;
use Sukhil\Database\Hive\Support\HiveValueQuoter;

/**
 * String-backed enum for testing literal().
 */
enum StringStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

/**
 * Int-backed enum for testing literal().
 */
enum IntStatus: int
{
    case Active = 1;
    case Inactive = 0;
}

/**
 * Stringable fixture for testing literal().
 */
final class StringableValue implements Stringable
{
    public function __construct(private string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}

final class HiveValueQuoterTest extends TestCase
{
    private HiveValueQuoter $quoter;

    protected function setUp(): void
    {
        $this->quoter = new HiveValueQuoter;
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
        $this->assertSame("'a\\000b'", $this->quoter->quoteString("a\0b"));
    }

    public function test_it_escapes_nul_unambiguously_when_digits_follow(): void
    {
        // Hive's lexer defines OctalEscape as '\' ('0'..'3')('0'..'7')('0'..'7'),
        // so a one-digit \0 followed by digits is swallowed into a multi-digit
        // octal escape: NUL,'1','2' emitted as '\012' decodes back as a single
        // newline (0x0A), silently corrupting the row. The escape caps at three
        // digits, so \000 followed by '1','2' round-trips intact.
        $this->assertSame("'A\\00012'", $this->quoter->quoteString("A\x00".'12'));
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

    public function test_literal_preserves_full_float_precision(): void
    {
        // A plain string cast honours PHP's precision=14 ini setting, which
        // would write 0.33333333333333 into the warehouse and discard three
        // significant digits of the double.
        $this->assertSame('0.3333333333333333', $this->quoter->literal(1 / 3));
        $this->assertSame((float) '0.3333333333333333', 1 / 3);
        $this->assertSame('0.1', $this->quoter->literal(0.1));
        $this->assertSame('1.0E+25', $this->quoter->literal(1.0e25));
    }

    public function test_literal_rejects_nan(): void
    {
        // NAN has no HiveQL literal; the bare word NAN parses as a column
        // reference or is rejected outright.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Cannot render the float NAN as a Hive literal: HiveQL has no NaN or infinity literal.'
        );

        $this->quoter->literal(NAN);
    }

    public function test_literal_rejects_infinity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Cannot render the float INF as a Hive literal: HiveQL has no NaN or infinity literal.'
        );

        $this->quoter->literal(INF);
    }

    public function test_literal_rejects_negative_infinity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Cannot render the float -INF as a Hive literal: HiveQL has no NaN or infinity literal.'
        );

        $this->quoter->literal(-INF);
    }

    public function test_literal_quotes_strings(): void
    {
        $this->assertSame("'x'", $this->quoter->literal('x'));
    }

    public function test_literal_renders_datetime_interface(): void
    {
        $date = new DateTimeImmutable('2026-07-25 14:30:05');
        $this->assertSame("'2026-07-25 14:30:05'", $this->quoter->literal($date));
    }

    public function test_literal_renders_string_backed_enum(): void
    {
        $this->assertSame("'active'", $this->quoter->literal(StringStatus::Active));
        $this->assertSame("'inactive'", $this->quoter->literal(StringStatus::Inactive));
    }

    public function test_literal_renders_int_backed_enum(): void
    {
        $this->assertSame('1', $this->quoter->literal(IntStatus::Active));
        $this->assertSame('0', $this->quoter->literal(IntStatus::Inactive));
    }

    public function test_literal_renders_stringable(): void
    {
        $stringable = new StringableValue("O'Reilly");
        $this->assertSame("'O\\'Reilly'", $this->quoter->literal($stringable));
    }

    public function test_literal_throws_for_unsupported_types(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot render value of type array as a Hive literal.');
        $this->quoter->literal([]);
    }

    public function test_it_handles_multibyte_utf8_safely(): void
    {
        // UTF-8 multibyte characters should pass through unchanged
        $this->assertSame("'café ✓ 😀'", $this->quoter->quoteString('café ✓ 😀'));
    }

    public function test_it_escapes_quotes_in_multibyte_strings(): void
    {
        // Multibyte characters with embedded quote to test escaping + multibyte together
        $this->assertSame("'naïve\\'s café'", $this->quoter->quoteString("naïve's café"));
    }
}
