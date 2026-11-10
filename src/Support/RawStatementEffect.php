<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

final readonly class RawStatementEffect
{
    /**
     * @param  list<string>  $tables
     */
    private function __construct(
        public array $tables,
        public Write $write,
        public bool $changesEverything,
    ) {}

    public static function none(): self
    {
        return new self([], Write::insert(), false);
    }

    /**
     * @param  non-empty-list<string>  $tables
     */
    public static function writes(array $tables, Write $write): self
    {
        return new self($tables, $write, false);
    }

    public static function everything(): self
    {
        return new self([], Write::unknown(), true);
    }

    /**
     * Narrows the write to the rows the database reported as affected.
     */
    public function affecting(int $rows): self
    {
        return $this->changesEverything ? $this : new self($this->tables, $this->write->affecting($rows), false);
    }

    public function isNone(): bool
    {
        return ! $this->changesEverything && $this->tables === [];
    }
}
