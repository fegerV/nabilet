<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Organization extends Model { protected $table = 'organizations'; protected $fillable = ['name', 'slug', 'description', 'logo', 'email', 'phone', 'status', 'settings_json'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    } }