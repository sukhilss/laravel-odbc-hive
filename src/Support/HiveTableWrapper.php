<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Support;

use InvalidArgumentException;

/**
 * Applies a table prefix to a table name — possibly aliased, possibly
 * schema-qualified — and validates every identifier segment before it is
 * emitted.
 *
 * This is the algorithm both HiveQueryGrammar::wrapTable() and
 * HiveSchemaGrammar::wrapTable() need: the query side reaches it on every
 * SELECT/INSERT/UPDATE/DELETE, the schema side on every DDL statement. Both
 * are the same security-relevant logic — a dotted table prefix must be
 * validated per segment, not as one concatenated string, or a legitimate
 * prefix like 'analytics.' throws — so it lives in exactly one place instead
 * of being duplicated (and, inevitably, re-diverged) across two grammars.
 *
 * Each grammar unwraps its own table-like argument first — an Expression on
 * the query side, a Blueprint on the schema side — and resolves the prefix
 * from the connection before calling here. This class only ever sees a plain
 * string table name and a plain string prefix.
 */
final class HiveTableWrapper
{
    /**
     * @throws InvalidArgumentException
     */
    public static function wrap(string $table, string $prefix): string
    {
        if (stripos($table, ' as ') !== false) {
            $segments = preg_split('/\s+as\s+/i', $table, 2);

            if (is_array($segments) && count($segments) === 2) {
                // An alias is never schema-qualified: Hive rejects
                // `as analytics.e` outright. So only the table portion of the
                // prefix applies to it — a prefix of 'analytics.' contributes
                // nothing to the alias, while 'pfx_' still contributes 'pfx_'.
                $aliasPrefix = str_contains($prefix, '.')
                    ? substr($prefix, (int) strrpos($prefix, '.') + 1)
                    : $prefix;

                // Checked before concatenation, or a non-empty prefix would
                // make an empty alias look non-empty and emit a bare `as pfx_`.
                if ($segments[1] === '') {
                    throw new InvalidArgumentException(
                        'Unsafe Hive identifier: the table alias is empty.'
                    );
                }

                // assertSafe(), not assertSafeQualified(): an alias is a single
                // identifier, so a dot inside it is never legitimate.
                return self::wrap($segments[0], $prefix)
                    .' as '.HiveIdentifier::assertSafe($aliasPrefix.$segments[1]);
            }
        }

        if (str_contains($table, '.')) {
            // The prefix belongs to the table, not to the schema that
            // qualifies it, so it is spliced in front of the final segment.
            $qualified = substr_replace($table, '.'.$prefix, (int) strrpos($table, '.'), 1);

            return HiveIdentifier::assertSafeQualified($qualified);
        }

        if ($table === '') {
            throw new InvalidArgumentException(
                'Unsafe Hive identifier: the table name is empty.'
            );
        }

        return HiveIdentifier::assertSafeQualified($prefix.$table);
    }
}
