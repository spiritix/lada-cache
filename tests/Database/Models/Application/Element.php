<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

final class Element extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function translations(): MorphMany
    {
        return $this->morphMany(TextTranslation::class, 'translatable');
    }
}
