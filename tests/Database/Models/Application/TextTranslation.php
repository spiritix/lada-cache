<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spiritix\LadaCache\Database\LadaCacheTrait;

final class TextTranslation extends Model
{
    use LadaCacheTrait;

    public $timestamps = false;

    protected $guarded = [];

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}
