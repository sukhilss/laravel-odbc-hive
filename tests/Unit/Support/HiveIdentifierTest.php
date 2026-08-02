<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sukhil\Database\Hive\Support\HiveIdentifier;

final class HiveIdentifierTest extends TestCase
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function safeIdentifiers(): array
    {
        return [
            'lowercase' => ['events'],
            'underscored' => ['event_name'],
            'digits' => ['events2024'],
            'leading underscore' => ['_internal'],
            'mixed case' => ['EventName'],
            'all digits' => ['2024'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function unsafeIdentifiers(): array
    {
        return [
            'empty' => [''],
            'space' => ['event name'],
            'dot' => ['db.events'],
            'hyphen' => ['event-name'],
            'single quote' => ["name'"],
            'double quote' => ['name"'],
            'backtick' => ['`name`'],
            'semicolon' => ['name; drop table users'],
            'comment marker' => ['name --'],
            'paren break-out' => ['name) values (@@x --'],
            'newline' => ["name\n"],
            'nul byte' => ["name\0"],
            'unicode' => ['naïve'],
        ];
    }

    #[DataProvider('safeIdentifiers')]
    public function test_it_returns_safe_identifiers_unchanged(string $identifier): void
    {
        $this->assertSame($identifier, HiveIdentifier::assertSafe($identifier));
    }

    #[DataProvider('unsafeIdentifiers')]
    public function test_it_rejects_unsafe_identifiers(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);

        HiveIdentifier::assertSafe($identifier);
    }

    public function test_the_message_shows_the_offending_identifier_unambiguously(): void
    {
        // var_export() rather than raw interpolation: the offending value is
        // frequently hostile input, and the message is what a developer reads
        // to work out what arrived.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Unsafe Hive identifier 'name) values (@@x --': "
            .'only letters, digits and underscores are permitted.'
        );

        HiveIdentifier::assertSafe('name) values (@@x --');
    }

    public function test_assert_safe_qualified_accepts_a_dotted_name(): void
    {
        $this->assertSame(
            'analytics.events',
            HiveIdentifier::assertSafeQualified('analytics.events')
        );
    }

    public function test_assert_safe_qualified_accepts_a_plain_name(): void
    {
        $this->assertSame('events', HiveIdentifier::assertSafeQualified('events'));
    }

    public function test_assert_safe_qualified_rejects_an_unsafe_segment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'ev ents'");

        HiveIdentifier::assertSafeQualified('analytics.ev ents');
    }

    public function test_assert_safe_qualified_rejects_an_empty_segment(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HiveIdentifier::assertSafeQualified('analytics..events');
    }

    public function test_assert_safe_qualified_rejects_an_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HiveIdentifier::assertSafeQualified('');
    }

    public function test_assert_safe_qualified_rejects_a_leading_dot(): void
    {
        // A leading dot produces an empty first segment via explode('.', ...),
        // which must not be silently skipped or treated as a root-qualified name.
        $this->expectException(InvalidArgumentException::class);

        HiveIdentifier::assertSafeQualified('.events');
    }

    public function test_assert_safe_qualified_rejects_a_trailing_dot(): void
    {
        // Symmetric case: an empty final segment must also be rejected.
        $this->expectException(InvalidArgumentException::class);

        HiveIdentifier::assertSafeQualified('events.');
    }
}
