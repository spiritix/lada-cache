<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\Query\Builder;

interface HasCompiledSubQueries
{
    /**
     * @return list<Builder>
     */
    public function getCompiledSubQueries(): array;
}
