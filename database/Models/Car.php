<?php

namespace Spiritix\LadaCache\Tests\Database\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spiritix\LadaCache\Database\LadaCacheTrait;

class Car extends Model
{
    use LadaCacheTrait;
    use HasFactory;

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
