<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use APCUIterator;
use Closure;

/**
 * Invalidations that could not reach Redis, kept until Redis accepts them again.
 *
 * They are always kept by the current object, which lives for the whole worker in FrankenPHP
 * worker mode or Octane. When APCu is available they are also kept in APCu, so the next request
 * of a PHP-FPM pool replays them even though every request starts fresh.
 */
final class FailedInvalidations
{
    private Invalidation $local;

    private function __construct(
        private readonly string $sharedPrefix,
        private readonly bool $shared,
        private readonly int $ttlSeconds,
    ) {
        $this->local = Invalidation::none();
    }

    public static function detect(string $sharedPrefix, int $ttlSeconds): self
    {
        return new self($sharedPrefix, function_exists('apcu_enabled') && apcu_enabled(), $ttlSeconds);
    }

    public static function processLocal(): self
    {
        return new self('', false, 0);
    }

    public function record(Invalidation $invalidation): void
    {
        if ($invalidation->isEmpty()) {
            return;
        }

        $this->local = $this->local->merge($invalidation);

        if ($this->shared) {
            apcu_store($this->sharedPrefix.'item:'.bin2hex(random_bytes(8)), serialize($invalidation), $this->ttlSeconds);
            apcu_add($this->counterKey(), 0);
            apcu_inc($this->counterKey());
        }
    }

    public function hasPending(): bool
    {
        if (! $this->local->isEmpty()) {
            return true;
        }

        return $this->shared && (int) apcu_fetch($this->counterKey()) > 0;
    }

    /**
     * Hands every pending invalidation to the replay as one merged invalidation and forgets
     * them once it returns. A throwing replay keeps them pending.
     *
     * @param  Closure(Invalidation): void  $replay
     */
    public function replay(Closure $replay): void
    {
        $counter = $this->shared ? (int) apcu_fetch($this->counterKey()) : 0;
        $sharedKeys = [];
        $merged = $this->local;

        if ($this->shared) {
            foreach (new APCUIterator('/^'.preg_quote($this->sharedPrefix.'item:', '/').'/') as $item) {
                $invalidation = unserialize((string) $item['value'], ['allowed_classes' => [Invalidation::class]]);

                if ($invalidation instanceof Invalidation) {
                    $merged = $merged->merge($invalidation);
                }

                $sharedKeys[] = (string) $item['key'];
            }
        }

        if ($merged->isEmpty()) {
            if ($this->shared) {
                apcu_cas($this->counterKey(), $counter, 0);
            }

            return;
        }

        $replay($merged);

        $this->local = Invalidation::none();

        if ($this->shared) {
            apcu_delete($sharedKeys);
            apcu_cas($this->counterKey(), $counter, 0);
        }
    }

    private function counterKey(): string
    {
        return $this->sharedPrefix.'count';
    }
}
