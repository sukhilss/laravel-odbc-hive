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
        "\0" => '\\0',
    ];

    /**
     * Escape a string and wrap it in single quotes.
     */
    public function quoteString(string $value): string
    {
        return "'" . strtr($value, self::ESCAPES) . "'";
    }

    /**
     * Render any supported value as a Hive SQL literal.
     */
    public function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => $this->quoteString($value),
            $value instanceof DateTimeInterface => $this->quoteString(
                $value->format('Y-m-d H:i:s')
            ),
            $value instanceof BackedEnum => $this->literal($value->value),
            $value instanceof Stringable => $this->quoteString((string) $value),
            default => throw new InvalidArgumentException(
                'Cannot render value of type ' . get_debug_type($value) . ' as a Hive literal.'
            ),
        };
    }
}
