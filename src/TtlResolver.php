<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Illuminate\Database\Eloquent\Model;
use Spiritix\LadaCache\Contracts\HasLadaTtl;

/**
 * Resolves the effective Lada Cache TTL for a given Eloquent model.
 *
 * Resolution order (first non-null wins):
 *   1. Model implements HasLadaTtl → $model->getLadaTtl()
 *   2. config('lada-cache.model_ttls.<FQCN>')
 *   3. null → caller defers to global config('lada-cache.expiration_time')
 *
 * Octane-safe: no mutable state, config is read once at construction.
 */
final class TtlResolver
{
    /** @var array<class-string, ?int> */
    private readonly array $modelTtls;

    public function __construct()
    {
        /** @var array<class-string, ?int> $configured */
        $configured = (array) config('lada-cache.model_ttls', []);
        $this->modelTtls = $configured;
    }

    public function resolve(?Model $model): ?int
    {
        if ($model === null) {
            return null;
        }

        if ($model instanceof HasLadaTtl) {
            $ttl = $model->getLadaTtl();

            if ($ttl !== null) {
                return $ttl;
            }
        }

        return $this->modelTtls[$model::class] ?? null;
    }
}
