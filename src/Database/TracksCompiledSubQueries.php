<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;

trait TracksCompiledSubQueries
{
    /** @var list<Builder> */
    private array $compiledSubQueries = [];

    /**
     * @return list<Builder>
     */
    public function getCompiledSubQueries(): array
    {
        return $this->compiledSubQueries;
    }

    /**
     * {@inheritDoc}
     *
     * @param  Closure|Builder|EloquentBuilder<*>|Relation<*, *, *>|string  $query
     * @return array<mixed>
     */
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
            $this->compiledSubQueries[] = $query;
        }

        return parent::createSub($query);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function withoutTrackingCompiledSubQueries(Closure $callback): mixed
    {
        $tracked = $this->compiledSubQueries;

        try {
            return $callback();
        } finally {
            $this->compiledSubQueries = $tracked;
        }
    }
}
