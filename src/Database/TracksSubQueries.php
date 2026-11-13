<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;

/**
 * Keeps every subquery passed through createSub(), which Laravel compiles into
 * raw SQL and drops, so the tables it reads can still be tagged.
 */
trait TracksSubQueries
{
    /** @var array<int, Builder> */
    private array $subQueries = [];

    /**
     * @return array<int, Builder>
     */
    public function getSubQueries(): array
    {
        return $this->subQueries;
    }

    /** {@inheritDoc} */
    protected function createSub($query)
    {
        if ($query instanceof Closure) {
            $callback = $query;
            $callback($query = $this->forSubQuery());
        }

        if ($query instanceof EloquentBuilder || $query instanceof Relation) {
            $query = $query->toBase();
        }

        if ($query instanceof Builder) {
            $this->subQueries[] = $query;
        }

        return parent::createSub($query);
    }
}
