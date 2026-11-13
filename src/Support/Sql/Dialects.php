<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * The dialect of each database. Dialects hold no state, so one instance per driver and MySQL
 * SQL mode is shared by every query, which is what lets `SqlMemo` keep results per dialect.
 */
final class Dialects
{
    private const array MYSQL_DRIVERS = ['mysql', 'mariadb'];

    /** @var array<string, Dialect> */
    private static array $shared = [];

    /** @var array<int, array<int, MySqlDialect>> by whether `"` quotes identifiers, then whether a backslash escapes */
    private static array $mySqlModes = [];

    /**
     * A MySQL connection configured with `modes` reads its SQL in those modes, as the session
     * Laravel sets them on does.
     */
    public static function of(ConnectionInterface $connection): Dialect
    {
        if (! $connection instanceof Connection) {
            throw new InvalidArgumentException('No SQL dialect for connection class '.$connection::class.', which is not a database connection.');
        }

        $driver = $connection->getDriverName();
        $modes = $connection->getConfig('modes');

        if (in_array($driver, self::MYSQL_DRIVERS, true) && is_array($modes) && $modes !== []) {
            return self::forMySqlMode(implode(',', array_filter($modes, 'is_string')));
        }

        return self::forDriver($driver);
    }

    public static function forDriver(string $laravelDriverName): Dialect
    {
        if (in_array($laravelDriverName, self::MYSQL_DRIVERS, true)) {
            return self::forMySqlMode('');
        }

        return self::$shared[$laravelDriverName] ??= match ($laravelDriverName) {
            'pgsql' => new PostgreSqlDialect,
            'sqlite' => new SQLiteDialect,
            'sqlsrv' => new MsSqlDialect,
            default => throw new InvalidArgumentException("No SQL dialect for database driver '{$laravelDriverName}'."),
        };
    }

    /**
     * The dialect of SQL MySQL reads under a `sql_mode`, such as a trigger created in it. MySQL
     * lists the modes a combination mode such as `ANSI` stands for, so `ANSI_QUOTES` is named
     * whenever it applies.
     */
    public static function forMySqlMode(string $sqlMode): MySqlDialect
    {
        $modes = array_fill_keys(explode(',', strtoupper($sqlMode)), true);
        $ansiQuotes = isset($modes['ANSI_QUOTES']);
        $backslashEscapes = ! isset($modes['NO_BACKSLASH_ESCAPES']);

        return self::$mySqlModes[(int) $ansiQuotes][(int) $backslashEscapes] ??= new MySqlDialect($ansiQuotes, $backslashEscapes);
    }
}
