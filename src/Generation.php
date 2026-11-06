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

    public static function fresh(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    public function scope(string $name): string
    {
        return $this->id.':'.$name;
    }

    public function retains(string $scopedName): bool
    {
        return str_starts_with($scopedName, $this->scope(''));
    }

    /**
     * @param  array<string>  $names
     * @return array<string>
     */
    public function scopeAll(array $names): array
    {
        return array_map($this->scope(...), $names);
    }
}
