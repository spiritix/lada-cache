<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Console;

use Illuminate\Console\Command;
use Spiritix\LadaCache\Services;
use Spiritix\LadaCache\Support\Metric;

/**
 * Reports the counters `lada-cache.metrics` collects: per table hits, misses, stores and the
 * invalidations it caused, and in total the retires, bypasses, failures and the statements that
 * cost hit rate.
 */
final class StatsCommand extends Command
{
    protected $signature = 'lada-cache:stats
        {--hours=24 : How many hours back to report}
        {--table= : Report this table only}
        {--json : Print the report as JSON}';

    protected $description = 'Report what the Lada Cache metrics counted.';

    private const array TABLE_METRICS = [Metric::Hit, Metric::Miss, Metric::Store, Metric::SkippedForSize, Metric::Invalidation, Metric::Cascade];

    public function handle(): int
    {
        $metrics = Services::metrics();

        if (! $metrics->isEnabled()) {
            $this->info('Lada Cache metrics are off. Set LADA_CACHE_METRICS=true to collect them.');

            return self::SUCCESS;
        }

        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($hours === false) {
            $this->error('--hours must be a whole number of 1 or more.');

            return self::FAILURE;
        }

        $report = $this->report($metrics->read(Services::redis(), $hours), $this->option('table'));

        if ($this->option('json')) {
            $this->line((string) json_encode(['hours' => $hours, ...$report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Table', 'Hits', 'Misses', 'Hit rate', 'Stores', 'Stores per hit', 'Too large', 'Invalidations', 'Cascade reach'],
            array_map(static fn (string $table, array $row): array => [
                $table, $row['hits'], $row['misses'], self::ratio($row['hit_rate'], '%.1f%%', 100), $row['stores'],
                self::ratio($row['stores_per_hit'], '%.2f'), $row['skipped_for_size'], $row['invalidations'], self::ratio($row['cascade_reach'], '%.1f'),
            ], array_keys($report['tables']), $report['tables']),
        );

        foreach (['retires' => 'Retires', 'bypasses' => 'Bypasses', 'failures' => 'Failures', 'unparsed_reads' => 'Reads run uncached, by statement', 'statements_changing_everything' => 'Statements retiring the cache'] as $key => $title) {
            foreach ($report[$key] as $label => $count) {
                $this->line(sprintf('%s: %s x%d', $title, $label, $count));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $counters  field => count, see `Metric::field()`
     * @return array{tables: array<string, array{hits: int, misses: int, hit_rate: float|null, stores: int, stores_per_hit: float|null, skipped_for_size: int, invalidations: int, cascade_reach: float|null}>, retires: array<string, int>, bypasses: array<string, int>, failures: array<string, int>, unparsed_reads: array<string, int>, statements_changing_everything: array<string, int>}
     */
    private function report(array $counters, mixed $onlyTable): array
    {
        $byMetric = [];
        foreach ($counters as $field => $count) {
            $counted = Metric::ofField($field);

            if ($counted !== null) {
                $byMetric[$counted[0]->value][$counted[1]] = $count;
            }
        }

        $tables = [];
        foreach (self::TABLE_METRICS as $metric) {
            $tables += $byMetric[$metric->value] ?? [];
        }

        $rows = [];
        foreach (array_keys($tables) as $table) {
            $table = (string) $table;

            if (is_string($onlyTable) && $onlyTable !== '' && $table !== $onlyTable) {
                continue;
            }

            $count = static fn (Metric $metric): int => $byMetric[$metric->value][$table] ?? 0;
            $hits = $count(Metric::Hit);
            $reads = $hits + $count(Metric::Miss);
            $invalidations = $count(Metric::Invalidation);
            $rows[$table] = [
                'hits' => $hits,
                'misses' => $count(Metric::Miss),
                'hit_rate' => $reads === 0 ? null : $hits / $reads,
                'stores' => $count(Metric::Store),
                'stores_per_hit' => $hits === 0 ? null : $count(Metric::Store) / $hits,
                'skipped_for_size' => $count(Metric::SkippedForSize),
                'invalidations' => $invalidations,
                'cascade_reach' => $invalidations === 0 ? null : $count(Metric::Cascade) / $invalidations,
            ];
        }

        ksort($rows, SORT_STRING);

        return [
            'tables' => $rows,
            'retires' => $byMetric[Metric::Retire->value] ?? [],
            'bypasses' => $byMetric[Metric::Bypass->value] ?? [],
            'failures' => $byMetric[Metric::Failure->value] ?? [],
            'unparsed_reads' => $byMetric[Metric::UnparsedRead->value] ?? [],
            'statements_changing_everything' => $byMetric[Metric::StatementChangingEverything->value] ?? [],
        ];
    }

    private static function ratio(?float $value, string $format, int $scale = 1): string
    {
        return $value === null ? '-' : sprintf($format, $value * $scale);
    }
}
