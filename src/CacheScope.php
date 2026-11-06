<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use InvalidArgumentException;

enum CacheScope: string
{
    case All = 'all';
    case Models = 'models';

    public static function fromConfig(mixed $value): self
    {
        return self::tryFrom((string) $value) ?? throw new InvalidArgumentException(sprintf(
            'Invalid lada-cache.scope "%s", expected one of: %s',
            (string) $value,
            implode(', ', array_column(self::cases(), 'value')),
        ));
    }
}
