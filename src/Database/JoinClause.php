<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause as BaseJoinClause;
use Spiritix\LadaCache\QueryHandler;

/**
 * Lada Cache-aware join clause.
 *
 * Extends Laravel's JoinClause to properly instantiate QueryBuilder instances
 * with the required QueryHandler when creating nested query builders.
 */
class JoinClause extends BaseJoinClause
{
    private readonly ?QueryHandler $parentHandler;

    private readonly ?Model $parentModel;

    /** {@inheritDoc} */
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

        // If parent was a LadaCache QueryBuilder, instantiate with handler & model
        if ($this->parentHandler !== null) {
            return new $class(
                $this->parentConnection,
                $this->parentHandler,
                $this->parentGrammar,
                $this->parentProcessor,
                $this->parentModel
            );
        }

        // Otherwise, use standard Laravel Builder instantiation
        return new $class($this->parentConnection, $this->parentGrammar, $this->parentProcessor);
    }
}
