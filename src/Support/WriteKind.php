<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

/**
 * How a write changed a table, which decides what else the database changed along with it:
 * every write changes the views over the table and what triggers write, while only updates
 * and deletes fire foreign key actions.
 */
enum WriteKind: string
{
    case Insert = 'a';
    case Modify = 'm';

    public function dependentsField(string $table): string
    {
        return $this->value.':'.$table;
    }

    public function merge(self $other): self
    {
        return $this === self::Modify || $other === self::Modify ? self::Modify : self::Insert;
    }
}
