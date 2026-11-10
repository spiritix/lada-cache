<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Closure;
use Spiritix\LadaCache\Services;
use Spiritix\LadaCache\Support\RawStatementClassifier;
use Spiritix\LadaCache\Support\Sql\Dialects;

trait InvalidatesRawStatements
{
    private int $builderStatementDepth = 0;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $statement
     * @return TResult
     */
    public function runBuilderStatement(Closure $statement): mixed
    {
        $this->builderStatementDepth++;

        try {
            return $statement();
        } finally {
            $this->builderStatementDepth--;
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $query
     * @param  array<mixed>  $bindings
     */
    protected function run($query, $bindings, Closure $callback)
    {
        $result = parent::run($query, $bindings, $callback);

        if ($this->builderStatementDepth === 0 && ! $this->pretending() && (bool) config('lada-cache.active', true)) {
            $this->invalidateForRawStatement((string) $query, is_int($result) ? $result : null);
        }

        return $result;
    }

    /**
     * @param  int|null  $affectedRows  the rows `affectingStatement()` reports changed, null for other statements
     */
    private function invalidateForRawStatement(string $query, ?int $affectedRows): void
    {
        $effect = RawStatementClassifier::classify($query, $this->getTablePrefix(), Dialects::of($this));

        if ($affectedRows !== null) {
            $effect = $effect->affecting($affectedRows);
        }

        if ($effect->isNone()) {
            return;
        }

        $handler = Services::handler();
        $handler->invalidateRawStatement($this, $effect);
    }
}
