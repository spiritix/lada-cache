<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * Remembers that Redis failed, so later operations skip it for a cooldown period instead of
 * each paying a connection timeout.
 *
 * The cooldown is always kept for the current object, which lives for the whole worker in
 * FrankenPHP worker mode or Octane. When APCu is available it is also shared through APCu,
 * so it spans requests and processes of a PHP-FPM pool, where every request starts fresh.
 */
final class RedisAvailability
{
    public const int COOLDOWN_SECONDS = 30;

    private ?int $unavailableUntil = null;

    private function __construct(
        private readonly string $sharedKey,
        private readonly bool $shared,
    ) {}

    public static function detect(string $sharedKey): self
    {
        return new self($sharedKey, function_exists('apcu_enabled') && apcu_enabled());
    }

    public static function processLocal(): self
    {
        return new self('', false);
    }

    public function isCoolingDown(): bool
    {
        if ($this->unavailableUntil !== null && hrtime(true) < $this->unavailableUntil) {
            return true;
        }

        return $this->shared && apcu_exists($this->sharedKey);
    }

    public function markUnavailable(): void
    {
        $this->unavailableUntil = hrtime(true) + self::COOLDOWN_SECONDS * 1_000_000_000;

        if ($this->shared) {
            apcu_store($this->sharedKey, true, self::COOLDOWN_SECONDS);
        }
    }

    public function markAvailable(): void
    {
        $this->unavailableUntil = null;

        if ($this->shared && apcu_exists($this->sharedKey)) {
            apcu_delete($this->sharedKey);
        }
    }
}
