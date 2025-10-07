<?php

namespace Spiritix\LadaCache\Tests\Database\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spiritix\LadaCache\Database\LadaCacheTrait;

class CarMaterial extends Model
{
    use HasFactory, LadaCacheTrait;

    protected $table = 'car_material';

    protected $fillable = ['car_id', 'material_id'];
}
