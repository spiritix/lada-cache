<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Calibration;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Repository for per-model calibrated TTL values stored in `lada_cache_calibrations`.
 *
 * Hot path optimization: the full calibration map is cached in memory for
 * `config('lada-cache.calibration.cache_ttl')` seconds — every cacheQuery would
 * otherwise hit DB. Invalidation is explicit (`bust()`) after `lada-cache:calibrate --apply`.
 *
 * Octane-safe: no mutable instance state beyond constructor-injected dependencies.
 */
final class TtlCalibrationRepository
{
    public const string CACHE_KEY = 'lada-cache:calibrations';

    public function __construct(
        private readonly string $tableName = 'lada_cache_calibrations',
    ) {}

    /**
     * Resolve the calibrated TTL for a model class, or null when none exists.
     */
    public function findForModel(string $modelClass): ?int
    {
        $map = $this->map();

        return $map[$modelClass] ?? null;
    }

    /**
     * Persist (upsert) a single calibration row and bust the cache.
     *
     * @param  array<string, mixed>  $metrics
     */
    public function upsert(string $modelClass, string $tableName, int $calibratedTtl, array $metrics): void
    {
        $this->upsertMany([[
            'model_class' => $modelClass,
            'table_name' => $tableName,
            'calibrated_ttl' => $calibratedTtl,
            'metrics' => $metrics,
        ]]);
    }

    /**
     * Persist multiple calibrations in a single SQL statement and bust the cache once.
     *
     * Each row: `['model_class' => string, 'table_name' => string, 'calibrated_ttl' => int, 'metrics' => array]`.
     * Empty input is a no-op (no DB round-trip, no cache bust).
     *
     * @param  list<array{model_class: string, table_name: string, calibrated_ttl: int, metrics: array<string, mixed>}>  $rows
     */
    public function upsertMany(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();
        $values = array_map(static fn (array $row): array => [
            'model_class' => $row['model_class'],
            'table_name' => $row['table_name'],
            'calibrated_ttl' => $row['calibrated_ttl'],
            'metrics' => json_encode($row['metrics'], JSON_THROW_ON_ERROR),
            'calibrated_at' => $now,
            'updated_at' => $now,
            'created_at' => $now,
        ], $rows);

        DB::table($this->tableName)->upsert(
            $values,
            ['model_class'],
            ['table_name', 'calibrated_ttl', 'metrics', 'calibrated_at', 'updated_at'],
        );

        $this->bust();
    }

    /**
     * Clear the in-memory map cache. Call after `--apply` writes new calibrations.
     */
    public function bust(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<class-string, int>
     */
    private function map(): array
    {
        $ttl = Config::integer('lada-cache.calibration.cache_ttl', 300);

        /** @var array<class-string, int> $cached */
        $cached = Cache::remember(self::CACHE_KEY, $ttl, function (): array {
            /** @var array<class-string, int> $rows */
            $rows = DB::table($this->tableName)
                ->select(['model_class', 'calibrated_ttl'])
                ->pluck('calibrated_ttl', 'model_class')
                ->map(static fn ($v): int => is_numeric($v) ? (int) $v : 0)
                ->all();

            return $rows;
        });

        return $cached;
    }
}
