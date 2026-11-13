<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/tokenizer.rs (Apache-2.0), commit 8a176d9: the Rust `char` predicates the tokenizer
 * and dialects rely on, applied to one UTF-8 encoded character.
 */
final class Character
{
    private const string ASCII_WHITESPACE = " \t\n\x0B\x0C\r";

    public static function isAscii(string $ch): bool
    {
        return strlen($ch) === 1 && ord($ch) < 0x80;
    }

    public static function isAsciiDigit(string $ch): bool
    {
        return strlen($ch) === 1 && ord($ch) >= 0x30 && ord($ch) <= 0x39;
    }

    public static function isAsciiAlphabetic(string $ch): bool
    {
        if (strlen($ch) !== 1) {
            return false;
        }
        $byte = ord($ch) | 0x20;

        return $byte >= 0x61 && $byte <= 0x7A;
    }

    public static function digitValue(string $ch, int $radix): ?int
    {
        if (strlen($ch) !== 1) {
            return null;
        }
        $byte = ord($ch);
        $value = match (true) {
            $byte >= 0x30 && $byte <= 0x39 => $byte - 0x30,
            ($byte | 0x20) >= 0x61 && ($byte | 0x20) <= 0x7A => ($byte | 0x20) - 0x61 + 10,
            default => null,
        };

        return $value !== null && $value < $radix ? $value : null;
    }

    public static function isDigit(string $ch, int $radix): bool
    {
        return self::digitValue($ch, $radix) !== null;
    }

    public static function isAlphabetic(string $ch): bool
    {
        return self::isAscii($ch) ? self::isAsciiAlphabetic($ch) : preg_match('/^\p{Alphabetic}$/u', $ch) === 1;
    }

    public static function isNumeric(string $ch): bool
    {
        return self::isAscii($ch) ? self::isAsciiDigit($ch) : preg_match('/^\p{N}$/u', $ch) === 1;
    }

    public static function isAlphanumeric(string $ch): bool
    {
        return self::isAlphabetic($ch) || self::isNumeric($ch);
    }

    public static function isWhitespace(string $ch): bool
    {
        return self::isAscii($ch) ? str_contains(self::ASCII_WHITESPACE, $ch) : preg_match('/^\p{White_Space}$/u', $ch) === 1;
    }

    /**
     * Mirrors Rust's `char::from_u32`: surrogates and values beyond U+10FFFF are not characters.
     */
    public static function fromCodePoint(int $codePoint): ?string
    {
        return match (true) {
            $codePoint < 0 || $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) => null,
            $codePoint < 0x80 => chr($codePoint),
            $codePoint < 0x800 => chr(0xC0 | $codePoint >> 6).chr(0x80 | $codePoint & 0x3F),
            $codePoint < 0x10000 => chr(0xE0 | $codePoint >> 12).chr(0x80 | $codePoint >> 6 & 0x3F).chr(0x80 | $codePoint & 0x3F),
            default => chr(0xF0 | $codePoint >> 18).chr(0x80 | $codePoint >> 12 & 0x3F).chr(0x80 | $codePoint >> 6 & 0x3F).chr(0x80 | $codePoint & 0x3F),
        };
    }
}
