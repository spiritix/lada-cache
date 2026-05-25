<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Contracts;

/**
 * Marker interface for Eloquent models that want to override the default Lada Cache TTL.
 *
 * Implementing this on a model lets the cache layer apply a per-model TTL instead of
 * the global config('lada-cache.expiration_time'). Resolution order is:
 *   1. Model::getLadaTtl() (this interface)
 *   2. config('lada-cache.model_ttls.<ClassName>')
 *   3. global config('lada-cache.expiration_time')
 *
 * Easiest way to opt in is via `LadaCacheTrait`, which already provides a
 * default `getLadaTtl()` implementation reading from a `public ?int $ladaTtl`
 * property declared on the model:
 *
 *     class City extends Model implements HasLadaTtl
 *     {
 *         use LadaCacheTrait;
 *         public ?int $ladaTtl = 86400 * 30; // 30 days
 *     }
 *
 * Override the method only when dynamic TTL logic is required.
 *
 * Semantics of the returned value:
 *   - null : defer to config-level fallback (model_ttls or global)
 *   - > 0  : TTL in seconds (e.g., 3600 = 1 hour)
 *   - 0    : persist forever (cache until tag-based invalidation) — same as
 *            setting global expiration_time to 0
 *   - < 0  : same as 0 (forever) — discouraged, prefer 0
 */
interface HasLadaTtl
{
    public function getLadaTtl(): ?int;
}
