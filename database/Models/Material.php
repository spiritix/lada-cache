<?php

namespace Spiritix\LadaCache\Tests\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Spiritix\LadaCache\Database\LadaCacheTrait;

class Material extends Model
{
    use LadaCacheTrait;

    public function cars()
    {
        return $this->belongsToMany(Car::class);
    }
}