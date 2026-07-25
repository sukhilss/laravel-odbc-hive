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
     * Probe the installed framework by inspecting Blueprint's constructor.
     */
    public static function detect(): self
    {
        $parameters = (new ReflectionMethod(Blueprint::class, '__construct'))->getParameters();
        $type = $parameters[0]->getType();

        return new self(
            $type instanceof ReflectionNamedType
                && is_a($type->getName(), Connection::class, true),
        );
    }

    /**
     * True on Laravel 12+, where Blueprint and the grammars receive a Connection.
     */
    public function usesConnectionAwareSchemaApi(): bool
    {
        return $this->connectionAwareSchemaApi;
    }
}
