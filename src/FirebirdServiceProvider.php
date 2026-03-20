<?php

declare(strict_types=1);

namespace Vkrapotkin\LaravelFirebird5;

use Illuminate\Support\ServiceProvider;
use Vkrapotkin\LaravelFirebird5\Connectors\FirebirdConnector;

class FirebirdServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->resolving('db', function ($db): void {
            $db->extend('firebird', function (array $config, string $name): FirebirdConnection {
                $connector = new FirebirdConnector();
                $pdo = $connector->connect($config);

                $connection = new FirebirdConnection(
                    $pdo,
                    $config['database'],
                    $config['prefix'] ?? '',
                    $config
                );

                $connection->setName($name);

                return $connection;
            });
        });
    }
}


