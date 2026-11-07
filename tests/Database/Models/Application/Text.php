<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spiritix\LadaCache\Database\LadaCacheTrait;

final class Text extends Model
{
    use LadaCacheTrait;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'checksum';

    protected $keyType = 'string';

    protected $guarded = [];

    public function translations(): HasMany
    {
        return $this->hasMany(TextTranslation::class, 'checksum', 'checksum');
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(TextAcceptance::class, 'checksum', 'checksum');
    }
}
