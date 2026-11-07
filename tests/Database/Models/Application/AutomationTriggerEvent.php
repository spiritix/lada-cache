<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;
use Spiritix\LadaCache\Database\LadaCacheTrait;

final class AutomationTriggerEvent extends Model
{
    use LadaCacheTrait;

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
