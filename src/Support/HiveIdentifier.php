<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Support;

use InvalidArgumentException;

/**
 * Validates identifiers that are emitted into HiveQL verbatim.
 *
 * The grammars in this package deliberately do NOT quote identifiers: Hive uses
 * double quotes to delimit string literals rather than identifiers, so the base
 * Grammar's `"name"` output is wrong here, and backticking every identifier
 * would change every statement this package has ever emitted.
 *
 * Emitting an identifier verbatim means the identifier IS the SQL, so it has to
 * be proven safe before it is emitted. Array keys reach the grammar straight
 * from user input in the very common `->insert($request->all())` and
 * `->select($request->input('columns'))` patterns, so anything unexpected must
 * fail loudly rather than be interpolated.
 *
 * The permitted set is exactly what Hive accepts in an unquoted identifier:
 * letters, digits and underscores. Anything else — a space, a quote, a comment
 * marker, a parenthesis, a semicolon, an empty string — is rejected.
 */
final class HiveIdentifier
{
    /**
     * The end anchor is \z, not $.
     *
     * PCRE's `$` also matches immediately before a trailing newline, so
     * "events\n" would satisfy `/^[A-Za-z0-9_]+$/` and be emitted verbatim —
     * a newline is enough to start a comment or a second statement in text
     * that is about to be concatenated into SQL. \z matches only the true end
     * of the subject.
     */
    private const PATTERN = '/^[A-Za-z0-9_]+\z/';

    /**
     * Return the identifier unchanged, or throw if it is not safe to emit.
     *
     * @throws InvalidArgumentException
     */
    public static function assertSafe(string $value): string
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Unsafe Hive identifier %s: only letters, digits and underscores are permitted.',
                var_export($value, true)
            ));
        }

        return $value;
    }
}
