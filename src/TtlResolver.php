<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Illuminate\Database\Eloquent\Model;
use Spiritix\LadaCache\Calibration\TtlCalibrationRepository;
use Spiritix\LadaCache\Contracts\HasLadaTtl;
use Throwable;

/**
 * Resolves the effective Lada Cache TTL for a given Eloquent model.
 *
 * Resolution order (first non-null wins):
 *   1. Model implements HasLadaTtl → $model->getLadaTtl()
 *   2. lada_cache_calibrations.calibrated_ttl (auto-calibration via lada-cache:calibrate)
 *   3. config('lada-cache.model_ttls.<FQCN>')
 *   4. null → caller defers to global config('lada-cache.expiration_time')
 *
 * Octane-safe: no mutable state, modelTtls read once at boot. Calibration
 * lookups go through TtlCalibrationRepository which caches the DB map in-memory
 * (Cache::remember) for `lada-cache.calibration.cache_ttl` seconds.
 *
 * Calibration repo is optional — if not bound or DB unavailable, resolver
 * gracefully skips layer 2 and falls through to config/global. This keeps
 * boot-time decoupled from DB availability.
 */
final class TtlResolver
{
    /** @var array<class-string, ?int> */
    private readonly array $modelTtls;

    public function __construct(
        private readonly ?TtlCalibrationRepository $calibrations = null,
    ) {
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

        if ($this->calibrations !== null && (bool) config('lada-cache.calibration.enabled', false)) {
            try {
                $calibrated = $this->calibrations->findForModel($model::class);

                if ($calibrated !== null) {
                    return $calibrated;
                }
            } catch (Throwable) {
                // DB unavailable / table missing: fall through to next layer
            }
        }

        return $this->modelTtls[$model::class] ?? null;
    }
}
