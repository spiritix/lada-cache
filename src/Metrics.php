<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use InvalidArgumentException;
use Spiritix\LadaCache\Support\ConfigValue;
use Spiritix\LadaCache\Support\Metric;
use Spiritix\LadaCache\Support\RedisReply;

/**
 * Counters of what the cache does, for measuring it in production: hits, misses, stores,
 * invalidations and their cascades per table, retires, bypasses, failures and the statements
 * that cost hit rate. They are opt-in (`lada-cache.metrics`).
 *
 * Counters live in one Redis hash per time bucket, outside the cache generation so a retire
 * keeps them, and expire after the retention. What a script does it counts itself, in the call
 * it already makes; what only PHP sees is counted here and written with one call when the
 * request or job ends.
 */
final class Metrics
{
    public const int DEFAULT_BUCKET_SECONDS = 3600;

    public const int DEFAULT_RETENTION_SECONDS = 8 * 86400;

    public const int WRITE_SECONDS = 10;

    /** @var array<string, int> field => count not written yet */
    private array $counted = [];

    /** @var int|null when the counts were last written, in `hrtime()` nanoseconds */
    private ?int $writtenAt = null;

    public function __construct(
        private readonly bool $enabled,
        private readonly int $bucketSeconds = self::DEFAULT_BUCKET_SECONDS,
        private readonly int $retentionSeconds = self::DEFAULT_RETENTION_SECONDS,
    ) {
        if ($bucketSeconds < 1) {
            throw new InvalidArgumentException(sprintf('Invalid lada-cache.metrics_bucket_seconds %d, expected 1 or more seconds', $bucketSeconds));
        }

        if ($retentionSeconds < $bucketSeconds) {
            throw new InvalidArgumentException(sprintf('Invalid lada-cache.metrics_retention_seconds %d, expected at least the bucket of %d seconds', $retentionSeconds, $bucketSeconds));
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            (bool) config('lada-cache.metrics', false),
            ConfigValue::integer('lada-cache.metrics_bucket_seconds', self::DEFAULT_BUCKET_SECONDS),
            ConfigValue::integer('lada-cache.metrics_retention_seconds', self::DEFAULT_RETENTION_SECONDS),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The script to run: the one given with metrics off, or the form of it that counts.
     */
    public function script(string $script): string
    {
        return $this->enabled ? LuaScripts::COUNTING[$script] : $script;
    }

    /**
     * The last arguments of a script that counts: the start of the current bucket, the label
     * the script counts under, and the retention of the bucket. With metrics off there are none,
     * so the scripts take what they always took.
     *
     * @return array{}|array{int, string, int}
     */
    public function scriptArguments(string $label = ''): array
    {
        return $this->enabled ? [$this->bucketStart(time()), $label, $this->retentionSeconds] : [];
    }

    public function count(Metric $metric, string $label, int $by = 1): void
    {
        if (! $this->enabled) {
            return;
        }

        $field = $metric->field($label);
        $this->counted[$field] = ($this->counted[$field] ?? 0) + $by;
    }

    /**
     * Writes what was counted here, if anything, in one call. A long-lived worker is asked
     * after every request or job and writes at most every `WRITE_SECONDS`, so counting costs
     * it no call per request; a process serving one request writes when that ends.
     */
    public function flush(Redis $redis): void
    {
        $now = hrtime(true);

        if ($this->counted === [] || ($this->writtenAt !== null && $now - $this->writtenAt < self::WRITE_SECONDS * 1_000_000_000)) {
            return;
        }

        $this->writtenAt = $now;

        $arguments = [];
        foreach ($this->counted as $field => $count) {
            array_push($arguments, $field, $count);
        }

        $this->counted = [];
        $redis->evaluate(LuaScripts::COUNT, [...$arguments, ...$this->scriptArguments()]);
    }

    /**
     * The counters of the buckets covering the last hours, summed per field.
     *
     * @return array<string, int>
     */
    public function read(Redis $redis, int $hours): array
    {
        $latest = $this->bucketStart(time());
        $earliest = $this->bucketStart(time() - $hours * 3600);
        $totals = [];

        for ($start = $latest; $start >= $earliest; $start -= $this->bucketSeconds) {
            $counters = $redis->getConnection()->command('hgetall', [$redis->prefix(self::bucketName($start))]);

            foreach (is_array($counters) ? $counters : [] as $field => $count) {
                $totals[(string) $field] = ($totals[(string) $field] ?? 0) + RedisReply::integer($count, 'metrics counter');
            }
        }

        ksort($totals, SORT_STRING);

        return $totals;
    }

    /**
     * The name of a bucket's hash after the key base, as the scripts build it too.
     */
    public static function bucketName(int|string $start): string
    {
        return self::BUCKET_PREFIX.$start;
    }

    public const string BUCKET_PREFIX = 'stats:';

    private function bucketStart(int $time): int
    {
        return intdiv($time, $this->bucketSeconds) * $this->bucketSeconds;
    }
}
