<?php

declare(strict_types=1);

namespace Vkrapotkin\LaravelFirebird5\Tests;

use Vkrapotkin\LaravelFirebird5\FirebirdServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [FirebirdServiceProvider::class];
    }
}


