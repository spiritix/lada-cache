<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/ast/dml.rs `Insert` (Apache-2.0), commit 8a176d9, reduced to what
 * it does to existing rows.
 */
final readonly class InsertStatement
{
    /**
     * @param  non-empty-list<string>  $table
     * @param  list<Assignment>|null  $conflictAssignments  what `ON DUPLICATE KEY UPDATE` or `ON CONFLICT DO UPDATE` assigns, null without one
     */
    public function __construct(
        public array $table,
        public bool $replacesRows,
        public ?array $conflictAssignments,
    ) {}
}
