<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Venue extends Model {
    protected $table = 'venues';
    protected $fillable = ['organization_id', 'name', 'slug', 'description', 'country', 'region', 'city', 'address', 'latitude', 'longitude', 'status'];

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

        public function organization(): BelongsTo
        {
            return $this->belongsTo(Organization::class, 'organization_id');
        }
    }