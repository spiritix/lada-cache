<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/ast/dml.rs `Merge` (Apache-2.0), commit 8a176d9, reduced to its target.
 */
final readonly class MergeStatement
{
    /**
     * @param  list<string>  $target
     */
    public function __construct(public array $target) {}
}
