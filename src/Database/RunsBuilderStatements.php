<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Closure;

interface RunsBuilderStatements
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $statement
     * @return TResult
     */
    public function runBuilderStatement(Closure $statement): mixed;
}
