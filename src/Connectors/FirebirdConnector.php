<?php

declare(strict_types=1);

namespace Vkrapotkin\LaravelFirebird5\Connectors;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use PDO;
use RuntimeException;

class FirebirdConnector extends Connector implements ConnectorInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): PDO
    {
        if (! extension_loaded('pdo_firebird')) {
            throw new RuntimeException('The pdo_firebird PHP extension is required to use the Firebird Laravel driver.');
        }

        $pdo = $this->createConnection(
            $this->getDsn($config),
            $config,
            $this->getOptions($config)
        );

        $this->configureConnection($pdo, $config);

        return $pdo;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function getDsn(array $config): string
    {
        $database = (string) ($config['database'] ?? '');

        if ($database === '') {
            throw new RuntimeException('Firebird connection requires a non-empty "database" value.');
        }

        $location = $database;

        if (! empty($config['host'])) {
            $location = (string) $config['host'];

            if (! empty($config['port'])) {
                $location .= '/'.(string) $config['port'];
            }

            $location .= ':'.$database;
        }

        $segments = ['dbname='.$location];

        if (! empty($config['charset'])) {
            $segments[] = 'charset='.(string) $config['charset'];
        }

        if (! empty($config['role'])) {
            $segments[] = 'role='.(string) $config['role'];
        }

        if (! empty($config['dialect'])) {
            $segments[] = 'dialect='.(string) $config['dialect'];
        }

        return 'firebird:'.implode(';', $segments);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function configureConnection(PDO $connection, array $config): void
    {
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
        $connection->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);

        if (! empty($config['isolation_level'])) {
            $connection->exec('SET TRANSACTION ISOLATION LEVEL '.(string) $config['isolation_level']);
        }
    }
}



