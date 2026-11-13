<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spiritix\LadaCache\Database\LadaCacheTrait;

final class Template extends Model
{
    use LadaCacheTrait;

    public const CREATED_AT = null;

    protected $guarded = [];

    public function newEloquentBuilder($query): OwnColumnsBuilder
    {
        return (new OwnColumnsBuilder($query))->select('templates.*');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(TemplatePermission::class);
    }
}
