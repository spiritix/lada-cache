<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

use UnexpectedValueException;

/**
 * What a foreign key does to the referencing rows when the referenced row is deleted or its
 * referenced columns are updated.
 */
enum ReferentialAction
{
    case KeepsRows;
    case Cascades;
    case SetsColumns;

    /**
     * Parses a rule as each database reports it: `CASCADE`, `SET NULL`, `SET_DEFAULT`, PostgreSQL's
     * one-letter codes, and so on.
     */
    public static function parse(string $rule): self
    {
        return match (strtolower(trim($rule))) {
            'cascade', 'c' => self::Cascades,
            'set null', 'set_null', 'set default', 'set_default', 'n', 'd' => self::SetsColumns,
            'no action', 'no_action', 'restrict', 'a', 'r' => self::KeepsRows,
            default => throw new UnexpectedValueException('Unknown foreign key rule '.json_encode($rule)),
        };
    }
}
