<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Detects which shape of the Illuminate schema API is installed.
 *
 * Laravel 12 changed Blueprint::__construct to take a Connection as its first
 * argument, and dropped the Connection argument from Grammar::compileCreate.
 * Laravel 11 retains the older signatures. This class is the single place in
 * the package that cares about the difference.
 */
final class IlluminateVersion
{
    public function __construct(
        private readonly bool $connectionAwareSchemaApi,
    ) {
    }

    /**
     * Probe a class by inspecting its constructor's first parameter type.
     *
     * Returns true if the first parameter is typed as Illuminate\Database\Connection.
     */
    public static function forClass(string $class): self
    {
        $parameters = (new ReflectionMethod($class, '__construct'))->getParameters();
        $firstParameter = $parameters[0] ?? null;
        $type = $firstParameter?->getType();

        return new self(
            $type instanceof ReflectionNamedType
                && is_a($type->getName(), Connection::class, true),
        );
    }

    /**
     * Probe the installed framework by inspecting Blueprint's constructor.
     */
    public static function detect(): self
    {
        return self::forClass(Blueprint::class);
    }

    /**
     * True on Laravel 12+, where Blueprint and the grammars receive a Connection.
     */
    public function usesConnectionAwareSchemaApi(): bool
    {
        return $this->connectionAwareSchemaApi;
    }
}
