<?php

namespace Spiritix\LadaCache\Tests\Database\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spiritix\LadaCache\Database\LadaCacheTrait;

class Engine extends Model
{
    use LadaCacheTrait;
    use HasFactory;

    public function car()
    {
        return $this->belongsTo(Car::class);
    }
}
