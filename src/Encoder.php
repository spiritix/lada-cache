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
 * - Arrays are encoded via PHP's native serialization to preserve complex nested
 *   structures and prevent ambiguity with JSON objects vs arrays when decoding.
 * - Scalars and null are encoded as JSON (throwing on errors) to remain compact,
 *   portable, and human-inspectable.
 * - Objects attempt JSON encoding first to support simple DTO-like objects; if
 *   that fails, fall back to native serialization for full fidelity.
 *
 * Architectural notes:
 * - The class is immutable and stateless; marked as readonly for clarity.
 * - Decoding tries JSON first, then unserialize(); if both fail, returns null.
 */
final readonly class Encoder
{
    public function encode(mixed $data): string
    {
        if (is_array($data)) {
            return serialize($data);
        }
        if (is_scalar($data) || $data === null) {
            return json_encode($data, JSON_THROW_ON_ERROR);
        }

        // For objects, prefer JSON to enable safe round-tripping of simple public data structures
        // native serialization for complex framework types.
        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return serialize($data);
        }
    }

    public function decode(?string $data): mixed
    {
        if ($data === null) {
            return null;
        }

        try {
            // If this is JSON, decode to arrays for arrays/scalars and to objects for JSON objects.
            $trimmed = ltrim($data);
            $assoc = ! str_starts_with($trimmed, '{');

            return json_decode($data, $assoc, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            try {
                // Suppress warnings and convert unserialize failures to null (except for serialized false 'b:0;').
                $result = @unserialize($data, ['allowed_classes' => true]);
                if ($result === false && $data !== 'b:0;') {
                    return null;
                }

                return $result;
            } catch (Throwable) {
                return null;
            }
        }
    }
}
