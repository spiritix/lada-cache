<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spiritix\LadaCache\Database\LadaCacheTrait;

final class ProviderSetting extends Model
{
    use HasUuids;
    use LadaCacheTrait;

    public $timestamps = false;

    protected $primaryKey = 'uuid';

    protected $guarded = [];
}
