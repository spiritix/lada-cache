<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use InvalidArgumentException;

final readonly class CacheMiss
{
    private const string FINGERPRINT_PATTERN = '/^[0-9a-f]{40}$/';

    public function __construct(
        public Generation $generation,
        public string $key,
        public string $fingerprint,
        public string $table = '',
    ) {
        if (preg_match(self::FINGERPRINT_PATTERN, $fingerprint) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid Lada Cache fingerprint "%s", expected 40 lowercase hex characters', $fingerprint));
        }
    }
}
