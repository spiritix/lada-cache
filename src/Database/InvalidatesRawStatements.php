<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Closure;
use Spiritix\LadaCache\QueryHandler;
use Spiritix\LadaCache\Support\RawStatementClassifier;

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

    /** {@inheritDoc} */
    protected function run($query, $bindings, Closure $callback)
    {
        $result = parent::run($query, $bindings, $callback);

        if ($this->builderStatementDepth === 0 && ! $this->pretending() && (bool) config('lada-cache.active', true)) {
            $this->invalidateForRawStatement((string) $query);
        }

        return $result;
    }

    private function invalidateForRawStatement(string $query): void
    {
        $effect = RawStatementClassifier::classify($query, $this->getTablePrefix());

        if ($effect->isNone()) {
            return;
        }

        /** @var QueryHandler $handler */
        $handler = app('lada.handler');
        $handler->invalidateRawStatement($this, $effect);
    }
}
