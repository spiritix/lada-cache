<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spiritix\LadaCache\Database\LadaCacheTrait;

final class Automation extends Model
{
    use LadaCacheTrait;

    public $timestamps = false;

    protected $guarded = [];

    public function newEloquentBuilder($query): OwnColumnsBuilder
    {
        return (new OwnColumnsBuilder($query))->select('automations.*');
    }

    public function triggerEvents(): HasMany
    {
        return $this->hasMany(AutomationTriggerEvent::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }
}
