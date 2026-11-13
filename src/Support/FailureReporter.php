<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Spiritix\LadaCache\CacheUnavailableException;
use Spiritix\LadaCache\Services;
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

        if (App::bound(Services::METRICS)) {
            Services::metrics()->count(Metric::Failure, strtolower($operation->name));
        }

        $message = "[LadaCache] Failed {$operation->value}, {$operation->consequenceOfFailure()}: {$exception->getMessage()}";
        $context = [...$context, 'exception' => $exception];

        if ($operation->compromisesFreshness()) {
            Log::error($message, $context);

            return;
        }

        Log::warning($message, $context);
    }
}
