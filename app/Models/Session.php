<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Session extends Model { protected $table = 'sessions'; protected $fillable = ['event_id', 'venue_id', 'hall_id', 'schema_version_id', 'starts_at', 'ends_at', 'sales_start_at', 'sales_end_at', 'timezone', 'status'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    } }