<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use JsonException;
use Throwable;

/**
 * Encoder
 *
 * Encodes and decodes cache payloads for storage.
 *
 * Strategy:
 * - Scalars, arrays, and null are encoded as JSON (with exceptions on error) to keep
 *   values portable and human-inspectable across services.
 * - Other values (e.g., objects) fall back to PHP's native serialization to retain
 *   fidelity for framework- or application-specific types.
 *
 * Architectural notes:
 * - The class is immutable and stateless; marked as readonly for clarity.
 * - Decoding first attempts JSON, then falls back to unserialize; if both fail,
 *   null is returned to indicate an un-decodable payload.
 */
final readonly class Encoder
{
    public function encode(mixed $data): string
    {
        if (is_array($data) || is_scalar($data) || $data === null) {
            return json_encode($data, JSON_THROW_ON_ERROR);
        }

        return serialize($data);
    }

    public function decode(?string $data): mixed
    {
        if ($data === null) {
            return null;
        }

        try {
            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            try {
                return unserialize($data, ['allowed_classes' => true]);
            } catch (Throwable) {
                return null;
            }
        }
    }
}
