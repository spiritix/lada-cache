<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Spiritix\LadaCache\QueryHandler;

use Closure;
use UnitEnum;

use function Illuminate\Support\enum_value;

/**
 * Decorates a Laravel database Connection to provide LadaCache-aware query builders
 * while remaining fully compatible with Eloquent and DB::extend().
 *
 * This class mirrors Laravel's public surface for creating query builders (e.g. `query()` and `table()`)
 * but routes them through the package's `QueryBuilder` to enable transparent caching/invalidation.
 */
final readonly class ConnectionDecorator
{
    private QueryHandler $handler;

    /**
     * The wrapped native Laravel connection and LadaCache query handler.
     */
    public function __construct(
        private Connection $connection,
        ?QueryHandler $handler = null,
    ) {
        // Prefer container resolution to support userland overrides
        $this->handler = $handler ?? app(QueryHandler::class);
    }

    /**
     * Create a LadaCache-aware query builder instance.
     */
    public function query(): QueryBuilder
    {
        return new QueryBuilder(
            $this->connection,
            $this->handler,
            $this->connection->getQueryGrammar(),
            $this->connection->getPostProcessor(),
        );
    }

    /**
     * Create a LadaCache-aware table builder (DB::table()).
     */
    public function table(Closure|BaseBuilder|Expression|UnitEnum|string $table, ?string $as = null): QueryBuilder
    {
        return $this->query()->from(enum_value($table), $as);
    }

    /**
     * Forward unhandled method calls to the underlying connection.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->connection->{$method}(...$parameters);
    }

    /**
     * Accessor for the database name (shortcut).
     */
    public function getDatabaseName(): string
    {
        return $this->connection->getDatabaseName();
    }

    /**
     * Return the grammar, processor, and config transparently.
     */
    public function getQueryGrammar(): Grammar
    {
        return $this->connection->getQueryGrammar();
    }

    public function getPostProcessor(): Processor
    {
        return $this->connection->getPostProcessor();
    }

    public function getConfig(?string $option = null): mixed
    {
        return $this->connection->getConfig($option);
    }
}
