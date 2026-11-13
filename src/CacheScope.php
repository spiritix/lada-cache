<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use InvalidArgumentException;
use Spiritix\LadaCache\Support\ConfigValue;

enum CacheScope: string
{
    case All = 'all';
    case Models = 'models';

    public static function fromConfig(): self
    {
        $value = ConfigValue::string('lada-cache.scope', self::All->value);

        return self::tryFrom($value) ?? throw new InvalidArgumentException(sprintf(
            'Invalid lada-cache.scope "%s", expected one of: %s',
            $value,
            implode(', ', array_column(self::cases(), 'value')),
        ));
    }
}
