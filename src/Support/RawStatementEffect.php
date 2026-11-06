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
        public bool $changesEverything,
    ) {}

    public static function none(): self
    {
        return new self([], false);
    }

    public static function writesTable(string $table): self
    {
        return new self([$table], false);
    }

    public static function everything(): self
    {
        return new self([], true);
    }

    public function isNone(): bool
    {
        return ! $this->changesEverything && $this->tables === [];
    }
}
