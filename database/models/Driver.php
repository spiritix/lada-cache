<?php

namespace Spiritix\LadaCache\Tests\Database\Models;

use Spiritix\LadaCache\Database\Model;

class Driver extends Model
{
    public function cars()
    {
        return $this->hasMany(Car::class);
    }
}