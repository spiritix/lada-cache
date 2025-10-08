<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use RuntimeException;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Support\RowExtractor;
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
 * - This class is marked `readonly` as its state is fully defined at construction.
 * - Method signatures and grammar calls are aligned with Laravel 12's query grammars.
 */
final readonly class Reflector
{
    public const string QUERY_TYPE_SELECT = 'select';

    public const string QUERY_TYPE_INSERT = 'insert';

    public const string QUERY_TYPE_UPDATE = 'update';

    public const string QUERY_TYPE_DELETE = 'delete';

    public const string QUERY_TYPE_TRUNCATE = 'truncate';

    private string $sqlOperation;

    public function __construct(
        private QueryBuilder $queryBuilder,
        string $sqlOperation = self::QUERY_TYPE_SELECT,
        private array $values = [],
    ) {
        $this->sqlOperation = strtolower($sqlOperation);
    }

    public function getDatabase(): string
    {
        return $this->queryBuilder->getConnection()->getDatabaseName();
    }

    public function getTables(): array
    {
        return TableExtractor::extractTables($this->queryBuilder);
    }

    public function getRows(): array
    {
        return RowExtractor::extractRows($this->queryBuilder);
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

    public function getParameters(): array
    {
        return $this->queryBuilder->getBindings();
    }

    public function inTransaction(): bool
    {
        $connection = $this->queryBuilder->getConnection();

        return method_exists($connection, 'transactionLevel') && $connection->transactionLevel() > 0;
    }
}
