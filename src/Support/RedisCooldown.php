<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * Whether a Redis call honours the cooldown after a failure. Calls that serve queries respect it
 * to keep latency bounded; calls whose loss compromises freshness and that no query waits on,
 * such as retiring after a schema change or replaying invalidations as the process ends, ignore it.
 */
enum RedisCooldown
{
    case Respect;
    case Ignore;
}
