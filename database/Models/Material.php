<?php

namespace Spiritix\LadaCache\Tests\Database\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spiritix\LadaCache\Database\LadaCacheTrait;

class Material extends Model
{
    use LadaCacheTrait;
    use HasFactory;

    public function cars()
    {
        return $this->belongsToMany(Car::class);
    }
}
