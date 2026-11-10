<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/ast/mod.rs `Truncate` (Apache-2.0), commit 8a176d9.
 */
final readonly class TruncateStatement
{
    /**
     * @param  non-empty-list<non-empty-list<string>>  $tables
     */
    public function __construct(public array $tables) {}
}
