<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/ast/query.rs `TableFactor` (Apache-2.0), commit 8a176d9, reduced
 * to what identifies a relation: a named table, or anything else (a derived table, a table
 * function, VALUES) with the relations nested inside it.
 */
final readonly class TableFactor
{
    /**
     * @param  non-empty-list<string>|null  $name  the parts of a named table's name, null for any other relation
     * @param  list<TableFactor>  $nested  the relations of a parenthesized join
     */
    private function __construct(
        public ?array $name,
        public ?string $alias,
        public array $nested,
    ) {}

    /**
     * @param  non-empty-list<string>  $name
     */
    public static function table(array $name, ?string $alias): self
    {
        return new self($name, $alias, []);
    }

    public static function derived(?string $alias): self
    {
        return new self(null, $alias, []);
    }

    /**
     * @param  list<TableFactor>  $relations
     */
    public static function nestedJoin(array $relations, ?string $alias): self
    {
        return new self(null, $alias, $relations);
    }

    /**
     * Every named table, including those of nested joins.
     *
     * @return list<TableFactor>
     */
    public function tables(): array
    {
        if ($this->name !== null) {
            return [$this];
        }

        return array_merge([], ...array_map(static fn (self $relation): array => $relation->tables(), $this->nested));
    }
}
