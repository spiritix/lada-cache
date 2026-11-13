<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\Query\Builder;

/**
 * A builder that keeps the subqueries Laravel compiles into raw SQL.
 */
interface HasSubQueries
{
    /**
     * @return array<int, Builder>
     */
    public function getSubQueries(): array;
}
