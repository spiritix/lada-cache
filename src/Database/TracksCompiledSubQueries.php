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

    /** {@inheritDoc} */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        $arguments = func_get_args();
        [, $comparisonOperator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        if ($this->isQueryable($column) && $comparisonOperator !== null) {
            $arguments[0] = $this->trackCompiledSubQuery($column);
        }

        return parent::where(...$arguments);
    }

    /** {@inheritDoc} */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if ($this->isQueryable($values)) {
            $values = $this->trackCompiledSubQuery($values);
        }

        return parent::whereIn($column, $values, $boolean, $not);
    }

    private function trackCompiledSubQuery(mixed $query): mixed
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

        return $query;
    }
}
