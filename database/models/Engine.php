<?php

namespace Spiritix\LadaCache\Tests\Database\Models;

use Spiritix\LadaCache\Database\Model;

class Engine extends Model
{
    public function car()
    {
        return $this->belongsTo(Car::class);
    }
}