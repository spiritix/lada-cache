<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\JoinClause;
use RuntimeException;
use Spiritix\LadaCache\Database\QueryBuilder;

/**
 * Reflects an underlying Laravel Query Builder to extract metadata and SQL.
 *
 * Purpose:
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
        $tables = [];

        $from = $this->queryBuilder->from;
        if (is_string($from)) {
            $tables[] = $from;
        } elseif ($from instanceof BaseBuilder) {
            $tables = array_merge($tables, (new self($from))->getTables());
        }

        foreach ($this->queryBuilder->joins ?? [] as $join) {
            if ($join instanceof JoinClause && is_string($join->table)) {
                $tables[] = $join->table;
            }
        }

        $this->extractTablesFromWhere($this->queryBuilder, $tables);

        return array_values(array_unique($tables));
    }

    private function extractTablesFromWhere(BaseBuilder $builder, array &$tables): void
    {
        foreach ($builder->wheres ?? [] as $where) {
            $type = $where['type'] ?? null;

            if ($type === 'Exists' || $type === 'NotExists') {
                $query = $where['query'] ?? null;
                if ($query instanceof BaseBuilder) {
                    if (is_string($query->from)) {
                        $tables[] = $query->from;
                    }

                    foreach ($query->joins ?? [] as $join) {
                        if ($join instanceof JoinClause && is_string($join->table)) {
                            $tables[] = $join->table;
                        }
                    }

                    $this->extractTablesFromWhere($query, $tables);
                }
            } elseif (($where['query'] ?? null) instanceof BaseBuilder) {
                $this->extractTablesFromWhere($where['query'], $tables);
            }
        }
    }

    /**
     * Extract targeted primary-key rows per table from simple where constraints.
     *
     * Returns an associative map of table => list of primary key values when
     * they can be statically determined from Basic or In wheres.
     *
     * @return array<string, array<int, scalar>>
     */
    public function getRows(): array
    {
        $rows = [];

        foreach ($this->queryBuilder->wheres ?? [] as $where) {
            if (!isset($where['column'])) {
                continue;
            }

            $columnRef = $where['column'];
            if (!str_contains($columnRef, '.') && is_string($this->queryBuilder->from)) {
                $columnRef = "{$this->queryBuilder->from}.{$columnRef}";
            }

            [$table, $column] = $this->splitTableAndColumn($columnRef);
            if ($column !== $this->queryBuilder->getPrimaryKeyName()) {
                continue;
            }

            $rows[$table] ??= [];

            if ($where['type'] === 'Basic' && ($where['operator'] ?? '=') === '=' && is_scalar($where['value'])) {
                $rows[$table][] = $where['value'];
            } elseif ($where['type'] === 'In' && isset($where['values']) && is_array($where['values'])) {
                $rows[$table] = array_merge($rows[$table], $where['values']);
            }
        }

        return $rows;
    }

    public function getType(): string
    {
        $sql = strtolower(trim($this->getSql()));
        $type = preg_replace('/[^a-z]/i', '', strtok($sql, ' ') ?: '');

        $valid = [
            self::QUERY_TYPE_SELECT,
            self::QUERY_TYPE_INSERT,
            self::QUERY_TYPE_UPDATE,
            self::QUERY_TYPE_DELETE,
            self::QUERY_TYPE_TRUNCATE,
        ];

        if (!in_array($type, $valid, true)) {
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

        return is_array($sql) ? implode('; ', $sql) : (string) $sql;
    }

    /**
     * @return array<int, mixed>
     */
    public function getParameters(): array
    {
        return $this->queryBuilder->getBindings();
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function splitTableAndColumn(string $identifier): array
    {
        if (!str_contains($identifier, '.')) {
            return [null, $identifier];
        }

        $parts = explode('.', $identifier);
        $column = array_pop($parts);
        $table = end($parts) ?: null;

        return [$table, $column];
    }
}
