<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause as BaseJoinClause;
use LogicException;
use Spiritix\LadaCache\QueryHandler;

/**
 * Lada Cache-aware join clause.
 *
 * Extends Laravel's JoinClause to properly instantiate QueryBuilder instances
 * with the required QueryHandler when creating nested query builders.
 */
class JoinClause extends BaseJoinClause implements HasCompiledSubQueries
{
    use TracksCompiledSubQueries;

    private readonly ?QueryHandler $parentHandler;

    private readonly ?Model $parentModel;

    /**
     * {@inheritDoc}
     *
     * @param  string  $type
     * @param  ExpressionContract|string  $table
     */
    public function __construct(Builder $parentQuery, $type, $table)
    {
        // Capture QueryBuilder-specific dependencies before parent constructor runs
        if ($parentQuery instanceof QueryBuilder) {
            $this->parentHandler = $parentQuery->getHandler();
            $this->parentModel = $parentQuery->getModel();
        } else {
            $this->parentHandler = null;
            $this->parentModel = null;
        }

        parent::__construct($parentQuery, $type, $table);
    }

    /** {@inheritDoc} */
    protected function newParentQuery()
    {
        $class = $this->parentClass;

        if ($this->parentHandler === null) {
            return is_a($class, Builder::class, true)
                ? new $class($this->parentConnection, $this->parentGrammar, $this->parentProcessor)
                : throw new LogicException('Join clause parent '.$class.' is not a query builder');
        }

        if (! is_a($class, QueryBuilder::class, true) || ! $this->parentConnection instanceof Connection) {
            throw new LogicException('Join clause parent '.$class.' carries a Lada Cache handler but is not a Lada Cache query builder on a database connection');
        }

        return new $class(
            $this->parentConnection,
            $this->parentHandler,
            $this->parentGrammar,
            $this->parentProcessor,
            $this->parentModel
        );
    }
}
