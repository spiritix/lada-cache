<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use InvalidArgumentException;

final readonly class Generation
{
    private const string ID_PATTERN = '/^[0-9a-f]{32}$/';

    public function __construct(
        public string $id,
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid Lada Cache generation id "%s", expected 32 lowercase hex characters', $id));
        }
    }
}
