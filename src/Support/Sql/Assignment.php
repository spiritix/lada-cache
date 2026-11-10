<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/ast/mod.rs `Assignment` (Apache-2.0), commit 8a176d9, without its value.
 */
final readonly class Assignment
{
    /**
     * @param  non-empty-list<non-empty-list<string>>  $targets  the assigned column names, several for a tuple target
     */
    public function __construct(public array $targets) {}
}
