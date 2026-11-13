<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Closure;
use PDO;
use Spiritix\LadaCache\Services;
use Spiritix\LadaCache\Support\RawStatementClassifier;
use Spiritix\LadaCache\Support\Sql\Dialects;
use Spiritix\LadaCache\Support\TransactionBoundary;
use WeakReference;

trait InvalidatesRawStatements
{
    private int $builderStatementDepth = 0;

    /** @var WeakReference<PDO>|null the PDO object a raw statement began a transaction on, until one ends it */
    private ?WeakReference $rawTransaction = null;

    /**
     * Drivers that do not report a transaction begun with a raw statement, as SQLite's does not
     * before PHP 8.4, leave this the only sign of it. It holds for the PDO object the statement
     * ran on, so a reconnect ends it.
     */
    public function inRawTransaction(): bool
    {
        $pdo = $this->rawTransaction?->get();

        return $pdo !== null && $pdo === $this->getRawPdo();
    }

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

        if (! (bool) config('lada-cache.active', true)) {
            return $result;
        }

        if ($this->builderStatementDepth === 0 && ! $this->pretending()) {
            $this->invalidateForRawStatement((string) $query, is_int($result) ? $result : null);
        }

        Services::handler()->settleEndedTransaction($this);

        return $result;
    }

    /**
     * @param  int|null  $affectedRows  the rows `affectingStatement()` reports changed, null for other statements
     */
    private function invalidateForRawStatement(string $query, ?int $affectedRows): void
    {
        $effect = RawStatementClassifier::classify($query, $this->getTablePrefix(), Dialects::of($this));
        $pdo = $this->getRawPdo();
        $this->rawTransaction = match ($effect->transaction) {
            TransactionBoundary::Begins => $pdo instanceof PDO ? WeakReference::create($pdo) : null,
            TransactionBoundary::Ends => null,
            null => $this->rawTransaction,
        };

        if ($affectedRows !== null) {
            $effect = $effect->affecting($affectedRows);
        }

        if ($effect->isNone()) {
            return;
        }

        $handler = Services::handler();
        $handler->invalidateRawStatement($this, $effect, $query);
    }
}
