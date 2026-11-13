<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

use UnexpectedValueException;

/**
 * Ported from sqlparser-rs src/tokenizer.rs (Apache-2.0), commit 8a176d9: `TokenizerError`, located by byte offset.
 */
final class TokenizerException extends UnexpectedValueException
{
    public function __construct(string $reason, public readonly int $offset)
    {
        parent::__construct("{$reason} at byte offset {$offset}");
    }
}
