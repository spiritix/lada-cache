<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use stdClass;

/**
 * Encoder
 *
 * Encodes and decodes cached query results for storage.
 *
 * Results are lists of rows as returned by PDO: stdClass objects or arrays of scalars.
 * Decoding only allows stdClass, so a tampered Redis entry cannot instantiate arbitrary
 * classes, and returns null for anything that is not a result.
 */
final readonly class Encoder
{
    private const array ALLOWED_CLASSES = [stdClass::class];

    /**
     * @param  array<mixed>  $rows
     */
    public function encode(array $rows): string
    {
        return serialize($rows);
    }

    /**
     * @return array<mixed>|null
     */
    public function decode(string $payload): ?array
    {
        if (! str_starts_with($payload, 'a:')) {
            return null;
        }

        $rows = @unserialize($payload, ['allowed_classes' => self::ALLOWED_CLASSES]);

        return is_array($rows) ? $rows : null;
    }
}
