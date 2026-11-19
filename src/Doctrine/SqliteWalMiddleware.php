<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * Enables SQLite WAL mode + busy timeout so concurrent HTTP multiplayer polling
 * runs smoothly without lock contention.
 */
#[AsMiddleware]
final class SqliteWalMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(#[SensitiveParameter] array $params): DriverConnection
            {
                $connection = parent::connect($params);
                $driverName = (string) ($params['driver'] ?? '');

                if (str_contains($driverName, 'sqlite')) {
                    $connection->exec('PRAGMA journal_mode=WAL');
                    $connection->exec('PRAGMA synchronous=NORMAL');
                    $connection->exec('PRAGMA busy_timeout=5000');
                    $connection->exec('PRAGMA foreign_keys=ON');
                }

                return $connection;
            }
        };
    }
}
