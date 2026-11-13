<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/ast/dml.rs `Delete` (Apache-2.0), commit 8a176d9, reduced to its
 * tables.
 */
final readonly class DeleteStatement
{
    /**
     * @param  list<non-empty-list<string>>  $targets  the tables listed before FROM (`DELETE a, b FROM ...`)
     * @param  list<TableFactor>  $from  the relations after FROM, joins included
     * @param  list<TableFactor>|null  $using  the relations after USING, null without USING
     */
    public function __construct(
        public array $targets,
        public array $from,
        public ?array $using,
    ) {}
}
