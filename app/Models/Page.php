<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $organization_id
 * @property string $title
 * @property string $slug
 * @property string|null $content
 * @property string $status
 * @property \Carbon\Carbon|null $published_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Page extends Model
{
    protected $fillable = [
        'public_id',
        'organization_id',
        'title',
        'slug',
        'content',
        'status',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(PageTranslation::class);
    }

        protected static function boot(): void
        {
            parent::boot();
            static::creating(function (self $model) {
                if (empty($model->public_id)) {
                    $model->public_id = (string) \Illuminate\Support\Str::ulid()->toBase32();
                }
            });
        }

}
