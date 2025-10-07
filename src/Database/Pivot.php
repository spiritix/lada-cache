<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\Eloquent\Relations\Pivot as BasePivot;

/**
 * LadaCache-aware Eloquent pivot model.
 *
 * This class ensures that Eloquent pivot models created by this package
 * utilize the LadaCache-aware query builder via `LadaCacheTrait`.
 */
class Pivot extends BasePivot
{
    use LadaCacheTrait;
}
