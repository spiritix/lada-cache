<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * A table a read depends on, as foreign key cascades into it see it: the read's lookup takes the
 * cascade tags of the table, and the columns the read pins keep it apart from cascades that
 * change only rows with other values in them.
 */
final readonly class CascadeRead
{
    /**
     * @param  string  $tagPrefix  the prefix of the table's cascade tags, see `Tagger::cascadeTagPrefix()`
     * @param  array<string, list<string>>  $pins  lowercase column => keys of the values it is pinned to, see `RowKeys::columnValues()`
     */
    public function __construct(
        public string $database,
        public string $table,
        public string $tagPrefix,
        public array $pins,
    ) {}
}
