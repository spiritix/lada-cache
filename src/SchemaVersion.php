<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

final readonly class SchemaVersion
{
    public const int TTL_SECONDS = 2;

    private const string MISSING_MIGRATIONS_TABLE = 'none';

    /**
     * @param  Closure(): string  $resolver
     */
    private function __construct(
        public string $database,
        private Closure $resolver,
    ) {}

    public static function fixed(string $database, string $version): self
    {
        return new self($database, static fn (): string => $version);
    }

    public static function ofConnection(Connection $connection): self
    {
        return new self($connection->getDatabaseName(), static fn (): string => self::readMigrationsVersion($connection));
    }

    public function resolve(): string
    {
        $version = ($this->resolver)();

        if (preg_match('/^[0-9a-z]+$/', $version) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid schema version "%s" for database "%s"', $version, $this->database));
        }

        return $version;
    }

    private static function readMigrationsVersion(Connection $connection): string
    {
        $table = config('database.migrations', 'migrations');
        $table = is_array($table) ? ($table['table'] ?? 'migrations') : $table;

        try {
            $row = $connection->selectOne('select max(id) as version from '.$connection->getQueryGrammar()->wrapTable((string) $table), [], false);
        } catch (QueryException) {
            return self::MISSING_MIGRATIONS_TABLE;
        }

        return (string) (int) ($row->version ?? 0);
    }
}
