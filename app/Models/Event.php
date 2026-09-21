<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Event extends Model {
    protected $table = 'events';
    protected $fillable = ['organization_id', 'category_id', 'title', 'slug', 'short_description', 'description', 'poster', 'cover', 'age_limit', 'duration_minutes', 'status', 'published_at', 'seo_title', 'seo_description', 'canonical_url', 'robots'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                            $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
                        }
                        if ($model->organization_id === null) {
                            $model->organization_id = auth()->user()?->organization_id ?? 1;
                        }
                    });
    }
}