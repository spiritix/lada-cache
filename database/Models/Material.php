<?php

namespace Spiritix\LadaCache\Tests\Database\Models;

use Spiritix\LadaCache\Database\Model;

class Material extends Model
{
    public function cars()
    {
        return $this->belongsToMany(Car::class);
    }
}