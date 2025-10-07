<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spiritix\LadaCache\Database\LadaCacheTrait;

class Engine extends Model
{
    use HasFactory, LadaCacheTrait;

    protected $fillable = ['name', 'car_id'];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }
}
