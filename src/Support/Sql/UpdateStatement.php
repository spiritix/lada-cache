<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/ast/dml.rs `Update` (Apache-2.0), commit 8a176d9, reduced to its
 * tables and assigned columns.
 */
final readonly class UpdateStatement
{
    /**
     * @param  list<TableFactor>  $tables  the relations of the UPDATE clause, joins included
     * @param  non-empty-list<Assignment>  $assignments
     */
    public function __construct(
        public array $tables,
        public array $assignments,
    ) {}
}
