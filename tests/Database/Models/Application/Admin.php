<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Admin extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class);
    }
}
