<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * The statement kinds a trigger fires on.
 */
enum TriggerEvent: string
{
    case Insert = 'i';
    case Update = 'u';
    case Delete = 'd';
    case Truncate = 't';
}
