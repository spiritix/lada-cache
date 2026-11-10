<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use RuntimeException;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Support\RowExtractor;
use Spiritix\LadaCache\Support\Sql\Dialect;
use Spiritix\LadaCache\Support\Sql\Dialects;
use Spiritix\LadaCache\Support\SqlTableScanner;
use Spiritix\LadaCache\Support\TableExtractor;

/**
 * Reflects an underlying Laravel Query Builder to extract metadata and SQL.
 *
 * Purpose:
 *
 * - Determine affected tables and primary key rows for cache tagging / invalidation.
 * - Compile the SQL and parameters for cache key construction and debugging.
 *
 * Architectural notes:
 * - Compiled SQL, tables and rows are memoized, since several collaborators read them per query.
 * - Method signatures and grammar calls are aligned with Laravel 12's query grammars.
 */
final class Reflector
{
    public const string QUERY_TYPE_SELECT = 'select';

    public const string QUERY_TYPE_INSERT = 'insert';

    public const string QUERY_TYPE_UPDATE = 'update';

    public const string QUERY_TYPE_DELETE = 'delete';

    public const string QUERY_TYPE_TRUNCATE = 'truncate';

    private readonly string $sqlOperation;

    private ?string $sql = null;

    /** @var list<string>|null */
    private ?array $tables = null;

    /** @var array<string, array<int, scalar>>|null */
    private ?array $rows = null;

    /** @var array<string, int>|null */
    private ?array $referenceCounts = null;

    /** @var array<string, array<string, list<scalar>>>|null */
    private ?array $columnPins = null;

    /**
     * @param  array<int|string, mixed>  $values
     */
    public function __construct(
        private readonly QueryBuilder $queryBuilder,
        string $sqlOperation = self::QUERY_TYPE_SELECT,
        private readonly array $values = [],
    ) {
        $this->sqlOperation = strtolower($sqlOperation);
    }

    public function getDatabase(): string
    {
        return $this->queryBuilder->getConnection()->getDatabaseName();
    }

    /**
     * The database server and database the query runs against, so results of equally named
     * databases on different servers never share cache entries.
     */
    public function getDialect(): Dialect
    {
        return Dialects::of($this->queryBuilder->getConnection());
    }

    public function getDatabaseServer(): string
    {
        $connection = $this->queryBuilder->getConnection();

        return json_encode([
            $connection->getDriverName(),
            $connection->getConfig('host'),
            $connection->getConfig('port'),
            $connection->getConfig('unix_socket'),
            $connection->getDatabaseName(),
        ], JSON_THROW_ON_ERROR);
    }

    public function isModelQuery(): bool
    {
        return $this->queryBuilder->getModel() !== null;
    }

    public function usesStringKeys(string $table): bool
    {
        $model = $this->queryBuilder->getModel();

        return $model !== null && $model->getTable() === $table && $model->getKeyType() !== 'int';
    }

    /**
     * Tables the query reads or writes. Reads also include tables named only inside raw SQL,
     * so a write to them still invalidates the read.
     *
     * @return list<string>
     */
    public function getTables(): array
    {
        return $this->tables ??= $this->sqlOperation === self::QUERY_TYPE_SELECT
            ? array_values(array_unique([
                ...TableExtractor::extractTables($this->queryBuilder),
                ...array_keys($this->referenceCounts()),
            ]))
            : TableExtractor::extractTables($this->queryBuilder);
    }

    /**
     * @return array<string, array<string, list<scalar>>> table => lowercase column => values its wheres pin it to
     */
    public function getColumnPins(): array
    {
        return $this->columnPins ??= RowExtractor::extractColumnPins($this->queryBuilder, $this->referenceCounts());
    }

    /**
     * @return array<string, array<int, scalar>>
     */
    public function getRows(): array
    {
        return $this->rows ??= RowExtractor::extractRows($this->queryBuilder, $this->referenceCounts());
    }

    /**
     * How often the statement reads each table, scanned once for both the tables to tag and the
     * tables whose rows can be pinned.
     *
     * @return array<string, int>
     */
    private function referenceCounts(): array
    {
        return $this->referenceCounts ??= SqlTableScanner::tableReferenceCounts(
            $this->getSql(),
            $this->queryBuilder->getConnection()->getTablePrefix(),
            $this->getDialect(),
        );
    }

    public function getType(): string
    {
        // Prefer the declared operation when set to a known type.
        $valid = [
            self::QUERY_TYPE_SELECT,
            self::QUERY_TYPE_INSERT,
            self::QUERY_TYPE_UPDATE,
            self::QUERY_TYPE_DELETE,
            self::QUERY_TYPE_TRUNCATE,
        ];

        if (in_array($this->sqlOperation, $valid, true)) {
            return $this->sqlOperation;
        }

        // Fallback: derive type from compiled SQL when operation is unspecified/custom
        $sql = strtolower(trim($this->getSql()));
        $type = preg_replace('/[^a-z]/i', '', strtok($sql, ' ') ?: '');

        if (! in_array($type, $valid, true)) {
            throw new RuntimeException("Invalid query type detected: {$type}");
        }

        return $type;
    }

    public function getSql(): string
    {
        return $this->sql ??= $this->compileSql();
    }

    private function compileSql(): string
    {
        $grammar = $this->queryBuilder->getGrammar();

        $sql = match ($this->sqlOperation) {
            self::QUERY_TYPE_INSERT => $grammar->compileInsert($this->queryBuilder, $this->values),
            'insertgetid' => $grammar->compileInsertGetId($this->queryBuilder, $this->values, null),
            self::QUERY_TYPE_UPDATE => $grammar->compileUpdate($this->queryBuilder, $this->values),
            self::QUERY_TYPE_DELETE => $grammar->compileDelete($this->queryBuilder),
            self::QUERY_TYPE_TRUNCATE => $grammar->compileTruncate($this->queryBuilder),
            default => $grammar->compileSelect($this->queryBuilder),
        };

        if (is_array($sql)) {
            // Some grammars (e.g., SQLite) return an array of SQL => bindings for certain
            // operations like TRUNCATE. In that case, join the SQL statements (keys).
            return implode('; ', array_keys($sql));
        }

        return (string) $sql;
    }

    /**
     * @return list<mixed>
     */
    public function getParameters(): array
    {
        return $this->queryBuilder->getBindings();
    }

    public function qualifiersNameDatabases(): bool
    {
        return in_array($this->queryBuilder->getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function isPretending(): bool
    {
        return $this->queryBuilder->getConnection()->pretending();
    }

    public function inTransaction(): bool
    {
        return $this->queryBuilder->getConnection()->transactionLevel() > 0;
    }
}
