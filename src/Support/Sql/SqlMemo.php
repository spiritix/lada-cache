<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

use Closure;
use WeakMap;

/**
 * What was read from SQL text, kept per dialect. An application issues the same statements over
 * and over with different bindings, so a long-lived worker tokenizes each of them once instead
 * of on every query. Results are kept under the hash of the SQL, so a long statement takes no
 * more memory than a short one, and the oldest one makes room once CAPACITY are kept. They live
 * as long as their dialect does, which `Dialects` shares per driver; a read that throws is not
 * kept.
 *
 * @template T
 */
final class SqlMemo
{
    public const int CAPACITY = 1024;

    /** @var WeakMap<Dialect, array<string, T>> */
    private WeakMap $results;

    public function __construct()
    {
        $this->results = new WeakMap;
    }

    /**
     * @param  Closure(): T  $read
     * @return T
     */
    public function remember(Dialect $dialect, string $sql, Closure $read): mixed
    {
        $key = hash('xxh128', $sql, true);
        $results = $this->results[$dialect] ?? [];

        if (array_key_exists($key, $results)) {
            return $results[$key];
        }

        $result = $read();
        $results = $this->results[$dialect] ?? [];
        unset($this->results[$dialect]);

        if (count($results) >= self::CAPACITY) {
            unset($results[array_key_first($results)]);
        }

        $results[$key] = $result;
        $this->results[$dialect] = $results;

        return $result;
    }
}
