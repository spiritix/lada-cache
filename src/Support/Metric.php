<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * What the metrics count. Each counter is a field `<metric>:<label>` of a time bucket's hash:
 * the label is the table of a read or write, the reason of a retire or bypass, the operation
 * that failed, or the shape of a statement.
 */
enum Metric: string
{
    case Hit = 'hit';
    case Miss = 'miss';
    case Store = 'store';
    case SkippedForSize = 'skip_size';
    case Invalidation = 'inval';
    case Cascade = 'cascade';
    case Retire = 'retire';
    case Bypass = 'bypass';
    case Failure = 'fail';
    case UnparsedRead = 'unparsed';
    case StatementChangingEverything = 'raw_everything';

    /**
     * The label of a read or write naming no table, and of the cascades of an invalidation that
     * covers the writes of several tables.
     */
    public const string NO_TABLE = '(none)';

    public const string SEVERAL_TABLES = '(several)';

    public function field(string $label): string
    {
        return $this->value.':'.($label === '' ? self::NO_TABLE : $label);
    }

    /**
     * @return array{self, string}|null the metric and label a field counts
     */
    public static function ofField(string $field): ?array
    {
        [$name, $label] = [...explode(':', $field, 2), ''];
        $metric = self::tryFrom($name);

        return $metric === null ? null : [$metric, $label];
    }
}
