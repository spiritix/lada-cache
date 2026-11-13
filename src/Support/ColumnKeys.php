<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * The keys of the values a column is compared with, once for each `ColumnType` the values can be
 * keyed for. Which of them names the rows is decided where the column's type is known: by the
 * scripts, from the dependency map. Values no type can key, or too many of them, leave it empty.
 */
final readonly class ColumnKeys
{
    public const int UNKEYED = -1;

    public const int AS_BEFORE = -2;

    /**
     * @param  array<string, list<string>>  $byType  `ColumnType` value => sorted keys
     */
    private function __construct(private array $byType) {}

    /**
     * @param  array<int|string, mixed>  $values
     */
    public static function of(array $values): self
    {
        $byType = [];

        foreach (ColumnType::cases() as $type) {
            $keys = RowKeys::forColumn($values, $type);

            if ($keys !== null) {
                $byType[$type->value] = $keys;
            }
        }

        return new self($byType);
    }

    public function isEmpty(): bool
    {
        return $this->byType === [];
    }

    /**
     * The keys of both sets of values. A type either cannot be keyed for, or that would hold too
     * many keys, is left out.
     */
    public function merge(self $other): self
    {
        $byType = [];

        foreach (array_intersect_key($this->byType, $other->byType) as $type => $keys) {
            $merged = array_values(array_unique([...$keys, ...$other->byType[$type]]));

            if (count($merged) <= RowKeys::MAX_PER_TABLE) {
                sort($merged, SORT_STRING);
                $byType[$type] = $merged;
            }
        }

        return new self($byType);
    }

    /**
     * For each `ColumnType` in order, as the scripts read them: the number of keys followed by
     * the keys, `self::UNKEYED` when the values cannot be keyed for it, or `self::AS_BEFORE`
     * when its keys are those of the type before it, as a decimal column's are for integers.
     *
     * @return list<int|string>
     */
    public function arguments(): array
    {
        $arguments = [];
        $before = null;

        foreach (ColumnType::cases() as $type) {
            $keys = $this->byType[$type->value] ?? null;

            match (true) {
                $keys === null => $arguments[] = self::UNKEYED,
                $keys === $before => $arguments[] = self::AS_BEFORE,
                default => array_push($arguments, count($keys), ...$keys),
            };

            $before = $keys;
        }

        return $arguments;
    }
}
