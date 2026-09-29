<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Predis\ClientInterface;
use Predis\Command\Processor\KeyPrefixProcessor;
use ReflectionClass;
use RuntimeException;
use Spiritix\LadaCache\Calibration\HitRatioAdjustment;
use Spiritix\LadaCache\Calibration\TtlCalibrationRepository;
use Spiritix\LadaCache\Database\LadaCacheTrait;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Stats\StatsReader;
use Throwable;

/**
 * Sample Redis OBJECT IDLETIME and recent cache activity for Lada-cached
 * models and derive per-model TTLs.
 *
 * Algorithm:
 *   1. Discover Eloquent models using LadaCacheTrait (or use --model=FQCN).
 *   2. SCAN the `lada:tags:database:*` keyspace ONCE per run and bucket the
 *      `:table_specific:<table>` and `:table_unspecific:<table>` tag sets by
 *      table; each model's cache keys come from its own tag sets.
 *   3. Run OBJECT IDLETIME per key → seconds since last access (pipelined).
 *   4. Compute P50, P95, max of the distribution.
 *   5. raw_calibrated = ceil(P95 × safety_factor).
 *   6. Floor against survivor-bias: max(raw, previousTtl / 2).
 *   7. Read recent activity for the table over the configured lookback window
 *      and adjust the TTL by signal:
 *        - 'idletime_only' — StatsReader unavailable or lookback=0; original behavior.
 *        - 'no_activity'   — reads+writes below `min_reads_for_signal`; original behavior.
 *        - 'write_heavy'   — invalidates/(hits+misses) ≥ `write_heavy_ratio`;
 *                            skip floor (raw_calibrated as-is), since invalidations
 *                            dominate any TTL extension we'd grant.
 *        - 'read_heavy'    — default activity path; hit-ratio proportional control.
 *   8. Skip models with samples < min_samples; persist only with --apply.
 *
 * Safety:
 *   - Aborts when Redis maxmemory-policy is *-lfu (IDLETIME is unsupported there).
 *   - Tolerates orphan SET members (cache key deleted, tag membership lingering).
 *   - Dry-run by default; --apply mutates the lada_cache_calibrations table only.
 *
 * Designed for periodic cron use. Reads Redis metadata only (no key mutation).
 */
final class CalibrateCommand extends Command
{
    protected $signature = 'lada-cache:calibrate
                            {--apply : Persist calibrated TTLs (default: dry-run)}
                            {--model= : Only calibrate the given fully-qualified model class}
                            {--safety-factor= : Override config safety_factor (P95 multiplier)}
                            {--models-path= : Override scanned directory (default: app_path("Models"))}
                            {--models-namespace= : Override scanned namespace (default: "App\\\\Models\\\\")}';

    protected $description = 'Sample Redis OBJECT IDLETIME and recent activity for Lada-cached models and compute per-model TTLs.';

    /**
     * Table-level tag keys found by this run's single SCAN round: table → tag
     * keys. null = not scanned yet in this run.
     *
     * @var array<string, array<string, true>>|null
     */
    private ?array $tagKeysByTable = null;

    public function __construct(
        private readonly ?Redis $redis = null,
        private readonly ?TtlCalibrationRepository $repository = null,
        private readonly ?StatsReader $statsReader = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Artisan reuses command instances in long-running workers.
        $this->tagKeysByTable = null;

        if (! (bool) config('lada-cache.active', true)) {
            $this->warn('Lada Cache is disabled — nothing to calibrate.');

            return self::SUCCESS;
        }

        // Check the flag before dependency guards so the zero-argument command
        // registered while calibration is disabled can exit cleanly.
        if (! (bool) config('lada-cache.calibration.enabled', false)) {
            $this->warn('Lada Cache calibration is disabled. Set LADA_CACHE_CALIBRATION_ENABLED=true to enable.');

            return self::SUCCESS;
        }

        if ($this->redis === null || $this->repository === null) {
            $this->error('Lada Cache calibration dependencies are not bound. Check service provider registration.');

            return self::FAILURE;
        }

        if (! $this->isIdleTimeSupported()) {
            $this->error('Redis uses an LFU eviction policy; OBJECT IDLETIME is unavailable. Calibration aborted.');

            return self::FAILURE;
        }

        $models = $this->discoverModels();

        if ($models === []) {
            $this->warn('No models using LadaCacheTrait were discovered.');

            return self::SUCCESS;
        }

        try {
            $safetyFactor = $this->resolveSafetyFactor();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $minSamples = Config::integer('lada-cache.calibration.min_samples', 50);
        $apply = (bool) $this->option('apply');

        $lookbackHours = Config::integer('lada-cache.calibration.activity_lookback_hours', 168);
        $this->warnIfLookbackExceedsBucketTtl($lookbackHours);
        // null  = activity signal unavailable (reader not configured OR Redis lookup failed);
        // []    = configured + reached Redis, but no activity recorded in window;
        // array = per-table activity counters.
        // We collapse the tri-state into an explicit `$statsState` enum-like
        // string so downstream branches read straightforwardly; the original
        // null/[] distinction below is what monitoring needs to tell a real
        // Redis outage ('unavailable' → 'idletime_only') from a cold window
        // ('empty' → 'no_activity').
        $activity = $this->loadActivity($lookbackHours);
        $statsState = match (true) {
            $activity === null => 'unavailable',
            $activity === [] => 'empty',
            default => 'available',
        };

        $rows = [];
        $counters = [
            'applied' => 0,
            'dry_run' => 0,
            'skipped_no_samples' => 0,
            'skipped_low_samples' => 0,
            'signal_idletime_only' => 0,
            'signal_no_activity' => 0,
            'signal_read_heavy' => 0,
            'signal_write_heavy' => 0,
        ];

        // Pending --apply rows are buffered and flushed in `$batchSize` chunks so we issue
        // O(models / batchSize) bulk UPSERTs instead of N single-row queries.
        $batchSize = max(1, Config::integer('lada-cache.calibration.batch_size', 100));
        $pending = [];

        foreach ($models as $modelClass => $tableName) {
            $metrics = $this->collectMetrics($tableName);

            // Defensive: even when min_samples is misconfigured to 0, never persist
            // a row with zero samples — calibrated_ttl would be 0 which means
            // "persist forever" and that's never what we want from an empty signal.
            if ($metrics['samples'] === 0 || $metrics['samples'] < $minSamples) {
                if ($metrics['samples'] === 0) {
                    $counters['skipped_no_samples']++;
                    // Log so operators can distinguish "model rarely used" from "table name
                    // mismatch / discovery bug" — both surface as 'skipped' in the output.
                    Log::info('[lada-cache:calibrate] No cache entries discovered for model.', [
                        'model' => $modelClass,
                        'table' => $tableName,
                    ]);
                } else {
                    $counters['skipped_low_samples']++;
                }

                $rows[] = [
                    $modelClass,
                    $tableName,
                    $metrics['samples'],
                    '-',
                    '-',
                    '-',
                    '-',
                    '-',
                    '-',
                    $metrics['samples'] === 0
                        ? 'skipped (no cache entries)'
                        : sprintf('skipped (< %d samples)', $minSamples),
                ];

                continue;
            }

            $rawCalibrated = (int) ceil($metrics['p95'] * $safetyFactor);
            $previousTtl = $this->resolveCurrentTtl($modelClass);

            // Survivor-bias guard: OBJECT IDLETIME can only sample keys still alive,
            // so P95 is always ≤ current TTL. Without a floor, repeated cron runs
            // would monotonically shrink TTL toward zero. Floor at half the previous
            // effective TTL so each calibration can move at most one octave down.
            $floor = $previousTtl > 0 ? (int) floor($previousTtl / 2) : 0;

            $tableActivity = $activity[$tableName] ?? ['hit' => 0, 'miss' => 0, 'invalidate' => 0];
            $reads = $tableActivity['hit'] + $tableActivity['miss'];
            $writes = $tableActivity['invalidate'];
            // hit_ratio = hits / reads. null = reads=0 (no signal — don't feed adjustment).
            // Sample threshold (min_reads_for_signal) is enforced in adjustForActivity;
            // here we only guard against division-by-zero.
            $hitRatio = $reads > 0 ? $tableActivity['hit'] / $reads : null;

            [$calibratedTtl, $signalSource] = $this->adjustForActivity(
                $rawCalibrated,
                $floor,
                $reads,
                $writes,
                $hitRatio,
                statsState: $statsState,
            );

            $counters['signal_'.$signalSource]++;

            $persistedMetrics = array_merge($metrics, [
                'previous_ttl' => $previousTtl,
                'raw_calibrated' => $rawCalibrated,
                'floor' => $floor,
                'safety_factor' => $safetyFactor,
                'reads' => $reads,
                'writes' => $writes,
                'hit_ratio' => $hitRatio,
                'signal_source' => $signalSource,
                'lookback_hours' => $lookbackHours,
            ]);

            if ($apply) {
                $pending[] = [
                    'model_class' => $modelClass,
                    'table_name' => $tableName,
                    'calibrated_ttl' => $calibratedTtl,
                    'metrics' => $persistedMetrics,
                ];

                if (count($pending) >= $batchSize) {
                    $this->repository()->upsertMany($pending);
                    $pending = [];
                }

                $counters['applied']++;
            } else {
                $counters['dry_run']++;
            }

            $rows[] = [
                $modelClass,
                $tableName,
                $metrics['samples'],
                $metrics['p50'],
                $metrics['p95'],
                $reads,
                $writes,
                $signalSource,
                $calibratedTtl,
                $apply ? 'applied' : 'dry-run',
            ];
        }

        // Residual rows that didn't fill the final batch.
        if ($pending !== []) {
            $this->repository()->upsertMany($pending);
        }

        $this->table(
            ['Model', 'Table', 'Samples', 'P50 (s)', 'P95 (s)', 'Reads', 'Writes', 'Signal', 'TTL (s)', 'Status'],
            $rows,
        );

        $this->info($apply
            ? 'Calibration persisted. TTL resolver will pick up new values on next read.'
            : 'Dry-run complete. Re-run with --apply to persist.');

        // Summary log for cron-style monitoring (Sentry, Horizon, log aggregator).
        // Operators can alert on sudden drops in `applied` or spikes in `skipped_no_samples`
        // without parsing console output.
        Log::info('[lada-cache:calibrate] Run summary', $counters + [
            'mode' => $apply ? 'apply' : 'dry-run',
            'safety_factor' => $safetyFactor,
            'min_samples' => $minSamples,
            'lookback_hours' => $lookbackHours,
            'models_total' => count($models),
        ]);

        return self::SUCCESS;
    }

    /**
     * Combine the IDLETIME-derived TTL with recent activity to pick a
     * final value and label the signal source for downstream observability.
     *
     * `$statsState` is the explicit tri-state from {@see handle()}:
     *   - 'unavailable' → reader not configured OR Redis lookup failed; report
     *                     as 'idletime_only' so monitoring sees the outage.
     *   - 'empty'       → reader reached Redis but the window held no events
     *                     for any table (also yields 'no_activity' when per-table
     *                     reads+writes is under the signal threshold).
     *   - 'available'   → per-table activity present; the real adjustment runs.
     *
     * On the read-heavy path, hit_ratio drives a convergent proportional
     * controller that pulls TTL toward `target_hit_ratio`:
     *   - adjustment = 1 + learning_rate × (target − actual)
     *   - clamped to [1 − max_step, 1 + max_step] (bounded per-run change)
     *   - |deviation| < deadband → no-op (oscillation guard around target)
     *   - the survivor-bias floor still applies (previousTtl / 2)
     * Combined, these three conditions form a bounded contraction map →
     * geometric convergence to the target hit_ratio when IDLETIME is stable.
     *
     * @param  'unavailable'|'empty'|'available'  $statsState
     * @return array{0:int, 1:string} [adjustedTtl, signalSource]
     */
    private function adjustForActivity(int $rawCalibrated, int $floor, int $reads, int $writes, ?float $hitRatio, string $statsState): array
    {
        if ($statsState === 'unavailable') {
            return [max($rawCalibrated, $floor), 'idletime_only'];
        }

        $minReads = Config::integer('lada-cache.calibration.min_reads_for_signal', 10);

        // `empty` (Redis returned no events for this window) and `available`
        // but per-table reads+writes below the threshold collapse to the same
        // 'no_activity' label — we have insufficient evidence either way.
        if ($statsState === 'empty' || ($reads + $writes) < $minReads) {
            // Too little observed traffic to draw a conclusion — preserve the
            // IDLETIME-only behavior so we don't shrink TTLs on cold tables.
            return [max($rawCalibrated, $floor), 'no_activity'];
        }

        // Clamp negative configs to 0 defensively. A negative threshold would
        // make ($writes / $reads) >= $writeRatio universally true and collapse
        // every table to write_heavy in a single cron run.
        $writeRatio = max(0.0, Config::float('lada-cache.calibration.write_heavy_ratio', 0.5));

        // reads=0 with writes>0 is degenerate (writes but no cache reads) — same
        // treatment as write-heavy: invalidation dominates, extending TTL is waste.
        $isWriteHeavy = $reads === 0
            || ($writes > 0 && ($writes / $reads) >= $writeRatio);

        if ($isWriteHeavy) {
            // Skip the survivor-bias floor: writes are invalidating keys before
            // their TTL fires anyway, so a longer TTL would only inflate memory
            // without improving the hit ratio.
            //
            // BUT floor at 1: calibrated_ttl=0 means "persist forever" in Lada
            // (Cache::set drops the EX argument), which is precisely the memory
            // leak we want to avoid on write-heavy tables when an invalidation
            // occasionally misses (raw SQL bypass, race, broken Observer, ...).
            return [max($rawCalibrated, 1), 'write_heavy'];
        }

        // Read-heavy path — convergent hit_ratio proportional control.
        // Adjustment is applied BEFORE floor; max(adj, floor) layers the
        // hit_ratio signal on top without dropping the survivor-bias guard.
        $adjusted = HitRatioAdjustment::apply(
            $rawCalibrated,
            $hitRatio,
            Config::float('lada-cache.calibration.target_hit_ratio', 0.80),
            Config::float('lada-cache.calibration.hit_ratio_deadband', 0.05),
            Config::float('lada-cache.calibration.hit_ratio_learning_rate', 0.30),
            Config::float('lada-cache.calibration.hit_ratio_max_step', 0.20),
        );

        return [max($adjusted, $floor), 'read_heavy'];
    }

    /**
     * Surface a self-contradicting config where the operator asked us to look
     * back further than the activity bucket retention. Older buckets have already
     * expired, so the pipeline reads return empty for the trailing keys.
     * Non-fatal — keeps the run going under the actual data we have.
     *
     * Fires regardless of whether StatsReader is bound. A misconfigured
     * `activity_lookback_hours > activity_bucket_ttl_seconds/3600` is still a misconfig
     * that operators should fix even if they haven't enabled stats yet
     * (silent until you do isn't helpful; alerting on the boundary now means
     * the next env that flips stats on starts clean).
     */
    private function warnIfLookbackExceedsBucketTtl(int $lookbackHours): void
    {
        if ($lookbackHours <= 0) {
            return;
        }

        $bucketTtlSeconds = Config::integer('lada-cache.calibration.activity_bucket_ttl_seconds', 86400 * 7);
        $bucketTtlHours = (int) floor($bucketTtlSeconds / 3600);

        if ($bucketTtlHours > 0 && $lookbackHours > $bucketTtlHours) {
            $this->warn(sprintf(
                'activity_lookback_hours (%d) exceeds bucket retention (%d h); older buckets have expired and will read empty.',
                $lookbackHours,
                $bucketTtlHours,
            ));
        }
    }

    /**
     * One-shot activity load for the whole calibration run. Single pipelined
     * Redis read for the lookback window.
     *
     * Returns:
     *   - null  → stats signal unavailable: reader not configured, lookback=0,
     *             OR Redis lookup threw. Caller labels these runs 'idletime_only'.
     *   - []    → reached Redis, but no activity recorded in the window
     *             (cold tables; operator hasn't enabled the counter yet).
     *   - array → per-table activity counters.
     *
     * The null vs [] distinction matters: collapsing them would misclassify a
     * Redis outage as "cold tables" and hide the failure from the signal
     * counter histogram in the cron summary log.
     *
     * @return array<string, array{hit:int, miss:int, invalidate:int}>|null
     */
    private function loadActivity(int $lookbackHours): ?array
    {
        if ($this->statsReader === null || $lookbackHours <= 0) {
            return null;
        }

        try {
            return $this->statsReader->readAllActivity($lookbackHours);
        } catch (Throwable $e) {
            // Don't fail the whole calibration just because activity load broke.
            // Operators still get the IDLETIME-driven TTL — they just lose the
            // read/write adjustment for this run.
            //
            // report() so the exception lands in monitoring; cron stderr is
            // often not captured by the scheduler in production deployments
            // and a silent "warn" would mean we'd never know StatsReader is broken.
            report($e);

            $this->warn(sprintf(
                'Activity stats unavailable (%s); falling back to IDLETIME-only signal.',
                $e->getMessage(),
            ));

            return null;
        }
    }

    /**
     * @throws InvalidArgumentException when an explicit --safety-factor <= 0 is supplied.
     */
    private function resolveSafetyFactor(): float
    {
        $override = $this->option('safety-factor');

        if ($override !== null && is_numeric($override)) {
            $value = (float) $override;

            if ($value <= 0.0) {
                throw new InvalidArgumentException(
                    'safety-factor must be > 0 (got '.$override.'); negative/zero would produce nonsensical TTLs.',
                );
            }

            return $value;
        }

        return Config::float('lada-cache.calibration.safety_factor', 2.0);
    }

    /**
     * Effective TTL currently in force for the given model class.
     * Walks the same resolution chain TtlResolver uses, minus the HasLadaTtl interface
     * (which depends on a Model instance — not relevant when we just need a floor).
     */
    private function resolveCurrentTtl(string $modelClass): int
    {
        $calibrated = $this->repository()->findForModel($modelClass);

        if ($calibrated !== null) {
            return $calibrated;
        }

        /** @var array<class-string, ?int> $modelTtls */
        $modelTtls = (array) config('lada-cache.model_ttls', []);

        if (array_key_exists($modelClass, $modelTtls) && $modelTtls[$modelClass] !== null) {
            return (int) $modelTtls[$modelClass];
        }

        return Config::integer('lada-cache.expiration_time', 0);
    }

    private function isIdleTimeSupported(): bool
    {
        try {
            $policy = $this->readRedisMaxmemoryPolicy();

            if ($policy === null) {
                $this->warn('Could not determine Redis maxmemory-policy (CONFIG GET returned non-array). Proceeding under assumption that OBJECT IDLETIME is supported.');

                return true;
            }

            if ($policy === '') {
                $this->warn('Redis maxmemory-policy not reported. Proceeding under assumption that OBJECT IDLETIME is supported.');

                return true;
            }

            return ! str_contains(strtolower($policy), 'lfu');
        } catch (Throwable) {
            // CONFIG GET may be ACL-disabled in managed Redis; assume IDLETIME works.
            return true;
        }
    }

    private function readRedisMaxmemoryPolicy(): ?string
    {
        $client = $this->redis()->getConnection()->client();

        if ($client instanceof \Redis) {
            $result = $client->config('GET', 'maxmemory-policy');
        } elseif (is_object($client) && is_callable([$client, 'config'])) {
            $result = $client->{'config'}('GET', 'maxmemory-policy');
        } else {
            return null;
        }

        if (! is_array($result)) {
            return null;
        }

        // PhpRedis: ['maxmemory-policy' => 'noeviction'] | Predis: ['maxmemory-policy', 'noeviction']
        $policy = $result['maxmemory-policy'] ?? $result[1] ?? null;

        return is_scalar($policy) ? (string) $policy : null;
    }

    /**
     * @return array<class-string<Model>, string>
     */
    private function discoverModels(): array
    {
        $explicit = $this->option('model');

        if (is_string($explicit) && $explicit !== '') {
            return $this->resolveSingleModel($explicit);
        }

        $modelsPath = $this->resolveModelsPath();
        $namespace = $this->resolveModelsNamespace();

        $models = [];

        if (! is_dir($modelsPath)) {
            return [];
        }

        foreach (File::allFiles($modelsPath) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->classFromPath($file->getPathname(), $modelsPath, $namespace);

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            if (! in_array(LadaCacheTrait::class, class_uses_recursive($class), true)) {
                continue;
            }

            try {
                /** @var Model $instance */
                $instance = $reflection->newInstanceWithoutConstructor();
                $models[$class] = $instance->getTable();
            } catch (Throwable) {
                continue;
            }
        }

        ksort($models);

        return $models;
    }

    private function resolveModelsPath(): string
    {
        $override = $this->option('models-path');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        return app_path('Models');
    }

    private function resolveModelsNamespace(): string
    {
        $override = $this->option('models-namespace');

        if (is_string($override) && $override !== '') {
            return rtrim($override, '\\').'\\';
        }

        return 'App\\Models\\';
    }

    /**
     * @return array<class-string<Model>, string>
     */
    private function resolveSingleModel(string $modelClass): array
    {
        if (! class_exists($modelClass)) {
            $this->error(sprintf('Model class %s does not exist.', $modelClass));

            return [];
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            $this->error(sprintf('%s is not an Eloquent model.', $modelClass));

            return [];
        }

        if (! in_array(LadaCacheTrait::class, class_uses_recursive($modelClass), true)) {
            $this->error(sprintf('%s does not use LadaCacheTrait.', $modelClass));

            return [];
        }

        /** @var Model $instance */
        $instance = (new ReflectionClass($modelClass))->newInstanceWithoutConstructor();

        return [$modelClass => $instance->getTable()];
    }

    private function classFromPath(string $path, string $basePath, string $namespace): ?string
    {
        $relative = ltrim(str_replace($basePath, '', $path), DIRECTORY_SEPARATOR);
        $withoutExt = preg_replace('/\.php$/', '', $relative);

        if ($withoutExt === null || $withoutExt === '') {
            return null;
        }

        return $namespace.str_replace(DIRECTORY_SEPARATOR, '\\', $withoutExt);
    }

    /**
     * @return array{samples:int, p50:int, p95:int, max:int}
     */
    private function collectMetrics(string $tableName): array
    {
        $idleTimes = [];

        foreach ($this->tagKeysFor($tableName) as $tagKey) {
            // Stream the SET via SSCAN — large tag sets can hold millions of cache keys
            // and a single SMEMBERS would block Redis and balloon client memory.
            foreach ($this->redis()->sScanMembers($tagKey) as $memberBatch) {
                foreach ($this->pipelinedIdleTimes($memberBatch) as $idle) {
                    $idleTimes[] = $idle;
                }
            }
        }

        return $this->computePercentiles($idleTimes);
    }

    /**
     * Table-level tag keys for the table (connection prefix stripped). The index
     * is built on the first call of each run.
     *
     * @return list<string>
     */
    private function tagKeysFor(string $tableName): array
    {
        $this->tagKeysByTable ??= $this->indexTagKeysByTable();

        return array_keys($this->tagKeysByTable[$tableName] ?? []);
    }

    /**
     * Walk the tag keyspace in a SINGLE SCAN round and bucket table-level tags
     * by table.
     *
     * SCAN MATCH visits every key in the database whatever the pattern, so a
     * round costs O(total keys). Scanning twice per model walked the whole
     * keyspace 2 × models times per run, pinning a single-core Redis for
     * minutes on large deployments. The glob cannot exclude row-scoped tags
     * (`...:<table>:row:<id>`), so they come back too; `tableOfTagKey()` drops them.
     *
     * @return array<string, array<string, true>>
     */
    private function indexTagKeysByTable(): array
    {
        $connPrefix = $this->connectionPrefix();
        $tagPrefix = $this->redis()->prefix('tags:database:');
        $index = [];

        // Neither Predis nor phpredis (by default) prefixes the SCAN MATCH
        // pattern, while the keys it returns carry the connection prefix.
        $patternPrefix = $this->clientPrefixesScanPattern() ? '' : $connPrefix;

        foreach ($this->redis()->scanKeys($patternPrefix.$tagPrefix.'*') as $batch) {
            foreach ($batch as $tagKey) {
                $tagKeyStripped = $connPrefix !== '' && str_starts_with($tagKey, $connPrefix)
                    ? substr($tagKey, strlen($connPrefix))
                    : $tagKey;

                $table = $this->tableOfTagKey($tagKeyStripped, $tagPrefix);

                // SCAN may return a key more than once; keying by the tag
                // key keeps its members from being counted twice.
                if ($table !== null) {
                    $index[$table][$tagKeyStripped] = true;
                }
            }
        }

        return $index;
    }

    /**
     * The prefix the Lada connection's client actually applies. Read from the
     * client, not `database.redis.options.prefix`: a connection-level `prefix`
     * overrides that (an empty one disables it), and phpredis skips `'0'`.
     */
    private function connectionPrefix(): string
    {
        $client = $this->redis()->getConnection()->client();

        if ($client instanceof \Redis) {
            // null when the client has no prefix.
            return (string) $client->getOption(\Redis::OPT_PREFIX);
        }

        if ($client instanceof ClientInterface) {
            $processor = $client->getOptions()->prefix;

            return $processor instanceof KeyPrefixProcessor ? $processor->getPrefix() : '';
        }

        return '';
    }

    /**
     * phpredis prefixes the SCAN pattern itself only when `Redis::SCAN_PREFIX`
     * is set on `Redis::OPT_SCAN` (Laravel's `scan` connection option).
     */
    private function clientPrefixesScanPattern(): bool
    {
        $client = $this->redis()->getConnection()->client();

        if (! $client instanceof \Redis) {
            return false;
        }

        $scanOption = $client->getOption(\Redis::OPT_SCAN);

        return ($scanOption & \Redis::SCAN_PREFIX) !== 0;
    }

    /**
     * Table of a `<prefix><db>:table_specific:<table>` or
     * `<prefix><db>:table_unspecific:<table>` key; null for row-scoped tags and
     * anything else.
     *
     * Selects the same keys as the `tags:database:*:table_specific:<table>`
     * glob: table names carry no colon, so the last `:` isolates the table.
     */
    private function tableOfTagKey(string $tagKey, string $tagPrefix): ?string
    {
        if (! str_starts_with($tagKey, $tagPrefix)) {
            return null;
        }

        $rest = substr($tagKey, strlen($tagPrefix));
        $lastColon = strrpos($rest, ':');

        if ($lastColon === false) {
            return null;
        }

        $scope = substr($rest, 0, $lastColon);
        $table = substr($rest, $lastColon + 1);

        if ($table === '') {
            return null;
        }

        return str_ends_with($scope, ':table_specific') || str_ends_with($scope, ':table_unspecific')
            ? $table
            : null;
    }

    /**
     * Run OBJECT IDLETIME for a batch of keys using a single Redis pipeline round-trip
     * instead of N synchronous calls. Falls back to sequential calls when the
     * underlying client does not expose a pipeline API.
     *
     * @param  array<int, string>  $keys
     * @return array<int, int>
     */
    private function pipelinedIdleTimes(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $client = $this->redis()->getConnection()->client();
        $idleTimes = [];

        foreach (array_chunk($keys, 100) as $chunk) {
            try {
                $raw = $this->runIdleTimeBatch($client, $chunk);
            } catch (Throwable) {
                continue;
            }

            foreach ($raw as $idle) {
                if (! is_numeric($idle)) {
                    continue;
                }

                $idleTimes[] = (int) $idle;
            }
        }

        return $idleTimes;
    }

    /**
     * Issue OBJECT IDLETIME for each key in a single Redis pipeline round-trip
     * (PhpRedis or Predis). Returns raw command results aligned with input order.
     *
     * @param  array<int, string>  $chunk
     * @return array<int, mixed>
     */
    private function runIdleTimeBatch(mixed $client, array $chunk): array
    {
        // PhpRedis: pipeline() switches the client into queue mode; the flush call
        // returns results in order. Each queued command returns $client (not the value).
        if ($client instanceof \Redis) {
            $client->pipeline();

            foreach ($chunk as $key) {
                $client->object('IDLETIME', $key);
            }

            // Dynamic method call below — phpredis exposes the pipeline flush
            // as a PHP reserved word, and some static analyzers (psalm /
            // phpstan strict modes) trip on direct calls even though the
            // method is real. The dynamic form sidesteps that false positive
            // without changing behavior.
            $pipelineFlush = 'exec';
            $results = $client->{$pipelineFlush}();

            return is_array($results) ? array_values($results) : [];
        }

        // Predis: pipeline() takes a closure and returns ordered results.
        // Its KeyPrefixProcessor has no OBJECT handler, so the key must carry
        // the connection prefix already (phpredis above prefixes it itself).
        if (is_object($client) && method_exists($client, 'pipeline')) {
            $connPrefix = $this->connectionPrefix();

            $results = $client->pipeline(static function (object $pipe) use ($chunk, $connPrefix): void {
                if (! is_callable([$pipe, 'object'])) {
                    return;
                }

                foreach ($chunk as $key) {
                    $pipe->{'object'}('IDLETIME', $connPrefix.$key);
                }
            });

            return is_array($results) ? array_values($results) : [];
        }

        // No pipeline API → sequential fallback.
        $results = [];

        foreach ($chunk as $key) {
            $results[] = is_object($client) && method_exists($client, 'object')
                ? $client->{'object'}('IDLETIME', $key)
                : null;
        }

        return $results;
    }

    private function redis(): Redis
    {
        if ($this->redis === null) {
            throw new RuntimeException('Lada Cache calibration Redis dependency is not bound.');
        }

        return $this->redis;
    }

    private function repository(): TtlCalibrationRepository
    {
        if ($this->repository === null) {
            throw new RuntimeException('Lada Cache calibration repository dependency is not bound.');
        }

        return $this->repository;
    }

    /**
     * @param  array<int, int>  $values
     * @return array{samples:int, p50:int, p95:int, max:int}
     */
    private function computePercentiles(array $values): array
    {
        $samples = count($values);

        if ($samples === 0) {
            return ['samples' => 0, 'p50' => 0, 'p95' => 0, 'max' => 0];
        }

        sort($values);

        return [
            'samples' => $samples,
            'p50' => $this->percentile($values, 50),
            'p95' => $this->percentile($values, 95),
            'max' => $values[$samples - 1],
        ];
    }

    /**
     * @param  array<int, int>  $sortedValues
     */
    private function percentile(array $sortedValues, int $percentile): int
    {
        $count = count($sortedValues);

        if ($count === 0) {
            return 0;
        }

        $index = (int) ceil(($percentile / 100) * $count) - 1;

        return $sortedValues[max(0, min($index, $count - 1))];
    }
}
