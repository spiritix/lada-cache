<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * Why the whole cache is retired, as the metrics name it.
 */
enum RetireReason: string
{
    case Migration = 'migration';
    case Schema = 'schema';
    case Flush = 'flush';
    case Statement = 'statement';
    case Fallback = 'fallback';
    case ServerChange = 'server_change';
    case UnknownTables = 'unknown_tables';
}
