<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Spiritix\LadaCache\Database\LadaCacheTrait;

final class AutomationRun extends Model
{
    use LadaCacheTrait;

    public $timestamps = false;

    protected $guarded = [];

    public function workspace(): HasOneThrough
    {
        return $this->hasOneThrough(Workspace::class, Automation::class, 'id', 'id', 'automation_id', 'workspace_id');
    }
}
