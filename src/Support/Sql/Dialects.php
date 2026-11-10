<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

final class Dialects
{
    public static function of(ConnectionInterface $connection): Dialect
    {
        if (! $connection instanceof Connection) {
            throw new InvalidArgumentException('No SQL dialect for connection class '.$connection::class.', which is not a database connection.');
        }

        return self::forDriver($connection->getDriverName());
    }

    public static function forDriver(string $laravelDriverName): Dialect
    {
        return match ($laravelDriverName) {
            'mysql', 'mariadb' => new MySqlDialect,
            'pgsql' => new PostgreSqlDialect,
            'sqlite' => new SQLiteDialect,
            'sqlsrv' => new MsSqlDialect,
            default => throw new InvalidArgumentException("No SQL dialect for database driver '{$laravelDriverName}'."),
        };
    }
}
