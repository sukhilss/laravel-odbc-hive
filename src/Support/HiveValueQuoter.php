<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Support;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;
use Stringable;

/**
 * Renders PHP values as Hive SQL literals.
 *
 * This exists because PDO_ODBC does not implement PDO::quote() — it returns
 * false, which silently collapses to an empty string and emits malformed SQL.
 * Hive uses C-style escaping inside string literals.
 */
final class HiveValueQuoter
{
    /**
     * Escape sequences applied simultaneously, so replacements are never
     * re-processed and a backslash cannot be escaped twice.
     *
     * @var array<string, string>
     */
    private const ESCAPES = [
        '\\' => '\\\\',
        "'" => "\\'",
        "\n" => '\\n',
        "\r" => '\\r',
        "\t" => '\\t',
        // Three digits, not one. Hive's lexer defines
        // OctalEscape: '\' ('0'..'3')('0'..'7')('0'..'7'), so a bare \0 followed
        // by digits is swallowed into a multi-digit octal escape: NUL,'1','2'
        // would emit '\012', which Hive decodes back as a single newline.
        // The escape caps at three digits, so \0001 is unambiguously NUL then 1.
        "\0" => '\\000',
    ];

    /**
     * Escape a string and wrap it in single quotes.
     */
    public function quoteString(string $value): string
    {
        return "'".strtr($value, self::ESCAPES)."'";
    }

    /**
     * Render any supported value as a Hive SQL literal.
     */
    public function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => $this->floatLiteral($value),
            is_string($value) => $this->quoteString($value),
            $value instanceof DateTimeInterface => $this->quoteString(
                $value->format('Y-m-d H:i:s')
            ),
            $value instanceof BackedEnum => $this->literal($value->value),
            $value instanceof Stringable => $this->quoteString((string) $value),
            default => throw new InvalidArgumentException(
                'Cannot render value of type '.get_debug_type($value).' as a Hive literal.'
            ),
        };
    }

    /**
     * Render a float without losing precision.
     *
     * A plain string cast honours PHP's `precision` ini setting, which defaults
     * to 14 significant digits — so 1/3 would be written to the warehouse as
     * 0.33333333333333, silently discarding three significant digits of every
     * double inserted. var_export() uses `serialize_precision` (-1 by default),
     * which emits the shortest representation that round-trips exactly.
     *
     * NAN and INF have no HiveQL literal at all: casting them produced the bare
     * words NAN and INF, which are parsed as column references or rejected
     * outright. They are refused here rather than emitted as invalid SQL.
     */
    private function floatLiteral(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot render the float %s as a Hive literal: HiveQL has no NaN or infinity literal.',
                is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF')
            ));
        }

        return var_export($value, true);
    }
}
