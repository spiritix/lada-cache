<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Models\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spiritix\LadaCache\Database\LadaCacheTrait;

final class Workspace extends Model
{
    use LadaCacheTrait;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $guarded = [];

    public function newEloquentBuilder($query): OwnColumnsBuilder
    {
        return (new OwnColumnsBuilder($query))->select('workspaces.*');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(WorkspaceGroup::class, 'group_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, WorkspaceLanguage::class)->withPivot('is_default');
    }
}
