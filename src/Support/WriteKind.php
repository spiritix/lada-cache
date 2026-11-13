<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * The kinds of `Write`, which decide the dependents fields it looks up.
 */
enum WriteKind
{
    case Insert;
    case ColumnUpdate;
    case AnyUpdate;
    case Delete;
    case Truncate;
    case Merge;
    case Unknown;
}
