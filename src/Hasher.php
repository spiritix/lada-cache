<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

/**
 * Hasher
 *
 * Produces a deterministic identifier for a SQL statement using reflection
 * metadata provided by `Reflector`.
 *
 * Strategy:
 * - Concatenate database name, raw SQL, and serialized parameters into a single
 *   canonical string and hash it for compact storage and lookup.
 *
 * Architectural notes:
 * - Stateless and immutable; declared as readonly.
 * - The hash is for uniqueness only; it is not intended for cryptographic use.
 */
final readonly class Hasher
{
    public function __construct(
        private readonly Reflector $reflector,
    ) {}

    public function getHash(): string
    {
        $identifier = implode('|', [
            $this->reflector->getDatabase(),
            $this->reflector->getSql(),
            serialize($this->reflector->getParameters()),
        ]);

        return sha1($identifier);
    }
}
