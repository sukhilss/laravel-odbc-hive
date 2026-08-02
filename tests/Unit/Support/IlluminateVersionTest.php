<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Sukhil\Database\Hive\Support\IlluminateVersion;

final class IlluminateVersionTest extends TestCase
{
    public function test_it_reports_connection_aware_api_when_constructed_true(): void
    {
        $version = new IlluminateVersion(true);

        $this->assertTrue($version->usesConnectionAwareSchemaApi());
    }

    public function test_it_reports_legacy_api_when_constructed_false(): void
    {
        $version = new IlluminateVersion(false);

        $this->assertFalse($version->usesConnectionAwareSchemaApi());
    }

    public function test_detect_matches_the_installed_blueprint_signature(): void
    {
        $firstParameter = (new \ReflectionMethod(Blueprint::class, '__construct'))
            ->getParameters()[0];
        $type = $firstParameter->getType();

        $expected = $type instanceof \ReflectionNamedType
            && is_a($type->getName(), Connection::class, true);

        $this->assertSame($expected, IlluminateVersion::detect()->usesConnectionAwareSchemaApi());
    }

    public function test_for_class_detects_connection_typed_first_parameter(): void
    {
        $this->assertTrue(
            IlluminateVersion::forClass(StubConnectionTyped::class)->usesConnectionAwareSchemaApi()
        );
    }

    public function test_for_class_rejects_untyped_first_parameter(): void
    {
        $this->assertFalse(
            IlluminateVersion::forClass(StubUntypedParameter::class)->usesConnectionAwareSchemaApi()
        );
    }

    public function test_for_class_rejects_union_type_first_parameter(): void
    {
        $this->assertFalse(
            IlluminateVersion::forClass(StubUnionTyped::class)->usesConnectionAwareSchemaApi()
        );
    }

    public function test_for_class_rejects_zero_parameter_constructor(): void
    {
        $this->assertFalse(
            IlluminateVersion::forClass(StubZeroParameters::class)->usesConnectionAwareSchemaApi()
        );
    }
}

/**
 * Stub: first parameter typed as Connection.
 *
 * These four stub classes exist purely to give IlluminateVersion::forClass()
 * known constructor signatures to reflect on; nothing ever calls them, so
 * their parameters are unused by design, and StubUntypedParameter's
 * parameter is deliberately left untyped because that absence is exactly
 * what it is testing.
 */
class StubConnectionTyped
{
    /** @phpstan-ignore constructor.unusedParameter */
    public function __construct(Connection $connection) {}
}

/**
 * Stub: first parameter untyped.
 */
class StubUntypedParameter
{
    /** @phpstan-ignore constructor.unusedParameter, missingType.parameter */
    public function __construct($table) {}
}

/**
 * Stub: first parameter with union type (not Connection alone).
 */
class StubUnionTyped
{
    /** @phpstan-ignore constructor.unusedParameter */
    public function __construct(Connection|string $param) {}
}

/**
 * Stub: constructor with zero parameters.
 */
class StubZeroParameters
{
    public function __construct() {}
}
