<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * Why a read went to the database without the cache being asked, as the metrics name it.
 */
enum BypassReason: string
{
    case Inactive = 'inactive';
    case Transaction = 'transaction';
    case Pretending = 'pretending';
    case Scope = 'scope';
    case ExcludedTable = 'excluded_table';
    case NonDeterministic = 'non_deterministic';
    case PendingInvalidations = 'pending_invalidations';
    case Unavailable = 'unavailable';
}
