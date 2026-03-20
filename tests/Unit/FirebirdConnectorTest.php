<?php

declare(strict_types=1);

namespace Vkrapotkin\LaravelFirebird5\Tests\Unit;

use Vkrapotkin\LaravelFirebird5\Connectors\FirebirdConnector;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class FirebirdConnectorTest extends TestCase
{
    public function test_it_builds_firebird_dsn(): void
    {
        $connector = new FirebirdConnector();
        $reflection = new ReflectionClass($connector);
        $method = $reflection->getMethod('getDsn');

        $dsn = $method->invoke($connector, [
            'host' => '127.0.0.1',
            'port' => 3050,
            'database' => 'C:\data\app.fdb',
            'charset' => 'UTF8',
            'role' => 'RDB$ADMIN',
            'dialect' => 3,
        ]);

        self::assertSame(
            'firebird:dbname=127.0.0.1/3050:C:\data\app.fdb;charset=UTF8;role=RDB$ADMIN;dialect=3',
            $dsn
        );
    }
}


