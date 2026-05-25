<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use ReflectionProperty;
use Spiritix\LadaCache\QueryHandler;

/**
 * Integrates Lada Cache into Eloquent models.
 *
 * Include this trait on models to route all base queries through the
 * Lada Cache-aware query builder, enabling transparent caching and
 * invalidation.
 *
 * Easiest way to override the TTL for a model:
 *
 *     class City extends Model implements HasLadaTtl
 *     {
 *         use LadaCacheTrait;
 *         public ?int $ladaTtl = 86400 * 30; // 30 days
 *     }
 *
 * Override `getLadaTtl()` only when dynamic logic is required. If the model
 * cannot be edited, fall back to `config('lada-cache.model_ttls.<FQCN>')`.
 */
trait LadaCacheTrait
{
    /**
     * Default implementation of {@see \Spiritix\LadaCache\Contracts\HasLadaTtl::getLadaTtl()}.
     *
     * Reads an optional `public ?int $ladaTtl` property declared on the model.
     * Models only need to declare the property; method override is reserved
     * for cases that require dynamic TTL logic.
     *
     * The property is intentionally NOT declared on the trait itself. PHP does
     * not allow a subclass to redeclare a trait-provided property with a
     * different default (fatal error: incompatible composition), so the trait
     * supplies only the method and lets the model own the property.
     */
    public function getLadaTtl(): ?int
    {
        if (! property_exists($this, 'ladaTtl')) {
            return null;
        }

        // A typed property declared without a default value (`public ?int $ladaTtl;`)
        // throws Error("must not be accessed before initialization") on direct read.
        // Guard with Reflection to avoid the crash and fall back to config resolution.
        if (! (new ReflectionProperty($this, 'ladaTtl'))->isInitialized($this)) {
            return null;
        }

        /** @var int|null $ttl */
        $ttl = $this->ladaTtl;

        return $ttl;
    }

    /** {@inheritDoc} */
    protected function newBaseQueryBuilder()
    {
        // When Lada Cache is disabled, use Laravel's default query builder
        if (! (bool) config('lada-cache.active', true)) {
            return parent::newBaseQueryBuilder();
        }

        $connection = $this->getConnection();

        /** @var QueryHandler $handler */
        $handler = app('lada.handler');

        return new QueryBuilder(
            $connection,
            $handler,
            $connection->getQueryGrammar(),
            $connection->getPostProcessor(),
            $this
        );
    }
}
