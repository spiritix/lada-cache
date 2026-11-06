<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

final readonly class CacheHit
{
    public function __construct(
        public mixed $value,
    ) {}
}
