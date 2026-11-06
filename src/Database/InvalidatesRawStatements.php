<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Closure;
use Spiritix\LadaCache\QueryHandler;
use Spiritix\LadaCache\Support\RawStatementClassifier;
use Spiritix\LadaCache\Support\RawStatementEffect;

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
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $result = parent::select($query, $bindings, $useReadPdo, $fetchUsing);
        $this->invalidateForRawStatement(fn (): RawStatementEffect => RawStatementClassifier::classifyQuery($query, $this->getTablePrefix()));

        return $result;
    }

    /** {@inheritDoc} */
    public function statement($query, $bindings = [])
    {
        $result = parent::statement($query, $bindings);
        $this->invalidateForRawStatement(fn (): RawStatementEffect => RawStatementClassifier::classifyStatement($query, $this->getTablePrefix()));

        return $result;
    }

    /** {@inheritDoc} */
    public function affectingStatement($query, $bindings = [])
    {
        $result = parent::affectingStatement($query, $bindings);
        $this->invalidateForRawStatement(fn (): RawStatementEffect => RawStatementClassifier::classifyStatement($query, $this->getTablePrefix()));

        return $result;
    }

    /** {@inheritDoc} */
    public function unprepared($query)
    {
        $result = parent::unprepared($query);
        $this->invalidateForRawStatement(fn (): RawStatementEffect => RawStatementClassifier::classifyStatement($query, $this->getTablePrefix()));

        return $result;
    }

    /**
     * @param  Closure(): RawStatementEffect  $classify
     */
    private function invalidateForRawStatement(Closure $classify): void
    {
        if ($this->builderStatementDepth > 0 || $this->pretending() || ! (bool) config('lada-cache.active', true)) {
            return;
        }

        $effect = $classify();

        if ($effect->isNone()) {
            return;
        }

        /** @var QueryHandler $handler */
        $handler = app('lada.handler');
        $handler->invalidateRawStatement($this, $effect);
    }
}
