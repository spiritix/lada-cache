<?php

namespace Spiritix\LadaCache\Tests\Database\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spiritix\LadaCache\Database\LadaCacheTrait;

class Car extends Model
{
    use HasFactory, LadaCacheTrait;

    protected $fillable = ['name', 'engine_id', 'driver_id'];

    public function engine(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Engine::class);
    }

    public function driver(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function materials(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Material::class);
    }
}
