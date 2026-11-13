<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

/**
 * Which reads Lada Cache caches, configured by `lada-cache.scope`.
 */
enum CacheScope: string
{
    /** Every query on a Lada connection. */
    case All = 'all';

    /** Only queries issued by models using LadaCacheTrait. */
    case Models = 'models';
}
