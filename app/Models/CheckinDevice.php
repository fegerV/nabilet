<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CheckinDevice extends Model { protected $table = 'checkin_devices'; protected $fillable = ['organization_id', 'name', 'device_token_hash', 'platform', 'app_version', 'status', 'last_seen_at'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    } }