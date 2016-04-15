<?php

namespace Spiritix\LadaCache\Tests\Database\Models;

use Spiritix\LadaCache\Database\Model;

class Car extends Model
{
    public function engine()
    {
        return $this->hasOne(Engine::class);
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function materials()
    {
        return $this->belongsToMany(Material::class);
    }
}