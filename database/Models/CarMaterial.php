<?php

namespace Spiritix\LadaCache\Tests\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Spiritix\LadaCache\Database\LadaCacheTrait;

class CarMaterial extends Model
{
    use LadaCacheTrait;

    protected $table = 'car_material';
}