<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

final readonly class AssignedRows
{
    /**
     * @param  array<string, list<int|string>>  $rows
     */
    public function __construct(
        public array $rows = [],
        public bool $complete = true,
    ) {}

    public static function unknown(): self
    {
        return new self([], false);
    }

    /**
     * @param  list<int|string>  $ids
     */
    public static function of(string $table, array $ids, bool $complete): self
    {
        return new self($ids === [] ? [] : [$table => $ids], $complete);
    }

    /**
     * @return list<int|string>
     */
    public function forTable(string $table): array
    {
        return $this->rows[$table] ?? [];
    }
}
