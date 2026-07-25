<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sukhil\Database\Hive\HiveServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [HiveServiceProvider::class];
    }
}
