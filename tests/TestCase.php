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

    protected function getEnvironmentSetUp($app): void
    {
        $database = getenv('FIREBIRD_TEST_DB') ?: dirname(__DIR__).DIRECTORY_SEPARATOR.'db'.DIRECTORY_SEPARATOR.'test.fdb';

        $app['config']->set('database.default', 'firebird');
        $app['config']->set('database.connections.firebird', [
            'driver' => 'firebird',
            'database' => $database,
            'charset' => 'UTF8',
            'username' => getenv('FIREBIRD_TEST_USER') ?: 'SYSDBA',
            'password' => getenv('FIREBIRD_TEST_PASSWORD') ?: '1619092230',
            'prefix' => '',
            'dialect' => 3,
        ]);
    }
}
