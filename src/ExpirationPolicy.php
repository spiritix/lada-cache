<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use InvalidArgumentException;
use Spiritix\LadaCache\Support\ConfigValue;

final readonly class ExpirationPolicy
{
    public function __construct(
        public int $seconds,
        public int $jitterPercent = 0,
    ) {
        if ($seconds < 0) {
            throw new InvalidArgumentException(sprintf('Invalid lada-cache.expiration_time %d, expected 0 or more seconds', $seconds));
        }

        if ($jitterPercent < 0 || $jitterPercent > 100) {
            throw new InvalidArgumentException(sprintf('Invalid lada-cache.expiration_jitter %d, expected a percentage from 0 to 100', $jitterPercent));
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            ConfigValue::integer('lada-cache.expiration_time', 0),
            ConfigValue::integer('lada-cache.expiration_jitter', 0),
        );
    }

    public function nextTtl(): int
    {
        if ($this->seconds === 0 || $this->jitterPercent === 0) {
            return $this->seconds;
        }

        $spread = intdiv($this->seconds * $this->jitterPercent, 100);

        return max(1, random_int($this->seconds - $spread, $this->seconds + $spread));
    }
}
