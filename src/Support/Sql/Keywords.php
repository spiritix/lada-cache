<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support\Sql;

/**
 * Ported from sqlparser-rs src/keywords.rs (Apache-2.0), commit 8a176d9: the keyword lists the
 * table factor and alias hooks consult.
 */
final class Keywords
{
    public const array RESERVED_FOR_TABLE_ALIAS = [
        'WITH', 'EXPLAIN', 'ANALYZE', 'SELECT', 'WHERE', 'GROUP', 'SORT', 'HAVING', 'ORDER', 'PIVOT', 'UNPIVOT', 'TOP',
        'LATERAL', 'VIEW', 'LIMIT', 'OFFSET', 'FETCH', 'UNION', 'EXCEPT', 'INTERSECT', 'MINUS', 'ON', 'JOIN', 'INNER',
        'CROSS', 'FULL', 'LEFT', 'RIGHT', 'NATURAL', 'USING', 'CLUSTER', 'DISTRIBUTE', 'GLOBAL', 'ANTI', 'SEMI',
        'RETURNING', 'OUTPUT', 'ASOF', 'MATCH_CONDITION', 'OUTER', 'SET', 'QUALIFY', 'WINDOW', 'END', 'FOR', 'PARTITION',
        'ARRAY', 'PREWHERE', 'SETTINGS', 'FORMAT', 'START', 'CONNECT', 'MATCH_RECOGNIZE', 'SAMPLE', 'TABLESAMPLE', 'FROM',
        'OPEN',
    ];

    public const array RESERVED_FOR_TABLE_FACTOR = ['INTO', 'LIMIT', 'HAVING', 'WHERE', 'ON'];
}
