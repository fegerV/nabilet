<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Webhook extends Model { protected $table = 'webhooks'; protected $fillable = ['organization_id', 'url', 'secret_encrypted', 'events_json', 'active', 'retry_limit'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    } }