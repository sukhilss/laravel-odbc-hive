<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Support;

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
            && is_a($type->getName(), \Illuminate\Database\Connection::class, true);

        $this->assertSame($expected, IlluminateVersion::detect()->usesConnectionAwareSchemaApi());
    }
}
