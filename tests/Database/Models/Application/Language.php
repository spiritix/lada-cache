<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;
use Spiritix\LadaCache\Database\LadaCacheTrait;

final class Language extends Model
{
    use LadaCacheTrait;

    public $timestamps = false;

    protected $guarded = [];
}
