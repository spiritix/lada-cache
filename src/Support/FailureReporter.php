<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Support\Facades\Log;
use Spiritix\LadaCache\CacheUnavailableException;
use Throwable;

final class FailureReporter
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function report(CacheOperation $operation, Throwable $exception, array $context = []): void
    {
        if ($exception instanceof CacheUnavailableException && ! $operation->compromisesFreshness()) {
            return;
        }

        $context = [...$context, 'exception' => $exception];

        if ($operation->compromisesFreshness()) {
            Log::error("[LadaCache] Failed {$operation->value}, cached results may be stale until they expire: {$exception->getMessage()}", $context);

            return;
        }

        Log::warning("[LadaCache] Failed {$operation->value}, continuing without the cache: {$exception->getMessage()}", $context);
    }
}
