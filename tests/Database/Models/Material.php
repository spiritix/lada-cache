<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spiritix\LadaCache\Database\LadaCacheTrait;

class Material extends Model
{
    use HasFactory, LadaCacheTrait;

    protected $fillable = ['name'];

    public function cars(): BelongsToMany
    {
        return $this->belongsToMany(Car::class);
    }
}
