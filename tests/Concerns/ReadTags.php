<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Concerns;

use Spiritix\LadaCache\Support\CascadeRead;

/**
 * What a read looks up: its tags and its tables as foreign key cascades into them see it.
 */
final readonly class ReadTags
{
    /**
     * @param  list<string>  $tags
     * @param  list<CascadeRead>  $cascadeReads
     */
    public function __construct(
        public array $tags,
        public array $cascadeReads,
    ) {}
}
